<?php
declare(strict_types=1);

namespace Zenexer\Telegram\Bot;

use Throwable;
use RuntimeException;
use danog\MadelineProto\API;
use danog\MadelineProto\Logger;

class App
{
	public const DRY_RUN = true;

	/** @var self */
	private static $instance;

	/** @var API */
	private $api;
	/** @var array */
	private $config;
	/** @var array */
	private $me;

	public function __construct(array $config)
	{
		$this->config = $config;
	}

	public static function getInstance(): self
	{
		return self::$instance;
	}

	public static function main(array $config): void
	{
		self::$instance = new App($config);
		self::getInstance()->run();
	}

	public function getApi(): API
	{
		return $this->api;
	}

	private function setApi(API $api): void
	{
		$this->api = $api;
	}

	private function getConfig(): array
	{
		return $this->config;
	}

	private function setMe(array $me): void
	{
		$this->me = $me;
	}

	public function getMe(): array
	{
		return $this->me;
	}

	public function log(...$args): void
	{
		Logger::log(...$args);
	}

	/**
	 * @return int[]
	 */
	private function getHandledSignals(): array
	{
		return [
			SIGINT,
			SIGTERM,
			SIGHUP,
			SIGQUIT,
			SIGUSR1,
		];
	}

	public function save(): void
	{
		echo "Forcing save...", PHP_EOL;
		try {
			$this->getApi()->serialize();
			echo "Done with forced save.", PHP_EOL;
		} catch (Throwable $ex) {
			echo "Error forcing save: $ex", PHP_EOL;
		}
	}

	public function shutdown(bool $save = true): void
	{
		if ($save) {
			echo "Performing final save before shutting down...", PHP_EOL;
			$this->save();
		}

		/** @var array $config */
		$config = $this->getConfig();
		if ($config['notifyOwner']) {
			try {
				echo "Notifying owner of shutdown...", PHP_EOL;

				/** @var API $api */
				$api = $this->getApi();
				$api->messages->sendMessage(['peer' => $config['owner'], 'message' => "Shutting down."]);
			} catch (Throwable $ex) {
				echo "Error notifying owner of shutdown: $ex", PHP_EOL;
			}
		}

		echo "Shutting down...", PHP_EOL;
		exit;
	}

	public function onSignal(int $signo, $siginfo): void
	{
		echo "Caught signal: $signo", PHP_EOL;

		switch ($signo) {
			case SIGINT:
			case SIGTERM:
			case SIGHUP:
				$this->shutdown(true);
				exit;

			case SIGQUIT:
				$this->shutdown(false);
				exit;

			case SIGUSR1:
				$this->save();
				break;
		}
	}

	public function run(): void
	{
		/** @var array $config */
		$config = $this->getConfig();

		/** @var API $api */
		$api = new API($config['sessionFile'], $config['apiSettings']);
		$this->setApi($api);

		$api->start();
		$this->save();

		/** @var array $me */
		$me = $api->get_self();
		$this->setMe($me);

		if (!$me['bot']) {
			throw new RuntimeException("Must be run as a bot.");
		}

		$api->setEventHandler(EventHandler::class);

		if ($config['notifyOwner']) {
			$api->messages->sendMessage(['peer' => $config['owner'], 'message' => "Bot online."]);
		}

		pcntl_async_signals(true);
		/** @var int $signo */
		foreach ($this->getHandledSignals() as $signo) {
			if (!pcntl_signal($signo, [$this, 'onSignal'])) {
				echo "Warning: failed to attach signal handler for signal $signo", PHP_EOL;
			}
		}
		
		$this->save();

		/** @var array<int> $adminedChannels */
		$adminedChannels = [];

		/** @var int $id */
		/** @var array $chat */
		foreach ($api->API->chats as $id => $chat) {
			switch ($chat['_']) {
				case 'channel':
					if (isset($chat['admin_rights']) && !empty($chat['admin_rights']['delete_messages']) && !empty($chat['admin_rights']['ban_users'])) {
						$adminedChannels[$id] = [
							'channel' => $chat,
							'pwr' => $api->get_pwr_chat($chat, true, false),
						];
					}
					break;
			}
		}

		/** @var int $channelId */
		/** @var array $channel */
		/** @var array $pwr */
		foreach ($adminedChannels as $channelId => ['channel' => $channel, 'pwr' => $pwr]) {
			/** @var array $users */
			$users = array_column($pwr['participants'], 'user');
			$this->checkUsers($channel, $channelId, $users);
			echo "Users in channel#$channelId: ", implode(', ', array_column($users, 'first_name')), PHP_EOL;
		}

		try {
			$api->loop();
			echo "Exited loop cleanly.", PHP_EOL;
		} catch (Throwable $ex) {
			echo "Fatal exception:", PHP_EOL;
			echo $ex, PHP_EOL;
		} finally {
			echo "Exited loop.", PHP_EOL;
			$this->shutdown(true);
		}
	}

	/**
	 * @return array<string, int>
	 */
	private function getBadNames(): array
	{
		return array_flip($this->getConfig()['badNames']);
	}

	/**
	 * @param array $inputChannel
	 * @param int   $channelId
	 * @param int[] $userIds
	 * @param int[]   $messageIds
	 */
	public function checkUsersByIds(array $inputChannel, int $channelId, array $userIds, array $messageIds = []): void
	{
		if (!$userIds) {
			return;
		}

		/** @var API */
		$api = $this->getApi();
		/** @var array[] $users */
		$users = $api->users->getUsers(['id' => $userIds]);

		$this->checkUsers($inputChannel, $channelId, $users, $messageIds);
	}

	/**
	 * @param array   $inputChannel
	 * @param int     $channelId
	 * @param array[] $users
	 * @param int[]   $messageIds
	 */
	public function checkUsers(array $inputChannel, int $channelId, array $users, array $messageIds = []): void
	{
		if (!$users) {
			return;
		}

		/** @var int $now */
		$now = time();
		/** @var array<string, int> $badNames */
		$badNames = $this->getBadNames();
		/** @var API $api */
		$api = $this->getApi();

		/** @var int $user */
		foreach ($users as $user) {
			if (array_key_exists($user['first_name'] ?? '', $badNames) || array_key_exists($user['last_name'] ?? '', $badNames)) {
				/** @var int $userId */
				$userId = $user['id'];

				echo "Kicking user#$userId from channel#$channelId", PHP_EOL;

				if (!empty($messageIds[$userId]) || !empty($messageIds[''])) {
					/** @var int[] $messageIds */
					$messageIds = array_unique(array_merge($messageIds[$userId] ?? [], $messageIds[''] ?? []));
					echo "Deleting messages: ", implode(', ', $messageIds), PHP_EOL;
					if (!self::DRY_RUN) {
						$api->channels->deleteMessages(['channel' => $inputChannel, 'id' => $messageIds]);
					}
				}

				echo "Banning user: $userId (", $user['first_name'] ?? '', ' ', $user['last_name'], ")", PHP_EOL;
				if (!self::DRY_RUN) {
					$api->channels->editBanned([
						'channel' => $inputChannel,
						'user_id' => $userId,
						'banned_rights' => [
							'_' => 'channelBannedRights',
							'view_messages' => true,
							'send_messages' => true,
							'send_media'    => true,
							'send_stickers' => true,
							'send_gifs'     => true,
							'send_games'    => true,
							'send_inline'   => true,
							'embed_links'   => true,
							'until_date'    => 0,
						]
					]);
				}
			}
		}
	}
}
