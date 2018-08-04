<?php
declare(strict_types=1);

namespace Zenexer\Telegram\Bot;

use Throwable;
use RuntimeException;
use danog\MadelineProto\API;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Exception as MadelineException;

class App
{
	private const RETRY_EXCEPTION_PREFIXES = [
		'Could not connect to DC ',
	];
	private const RETRY_EXCEPTION_MATCHES = [
		'socket_connect(): unable to connect [',
	];

	/** @var self */
	private static $instance;

	/** @var API */
	private $api;
	/** @var array */
	private $config;
	/** @var array */
	private $me;
	/** @var bool */
	private $shuttingDown = false;

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

	public function isDryRun(): bool
	{
		/** @var array $config */
		$config = $this->getConfig();
		return (bool) $config['dryRun'];
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

	public function notify(string $message): void
	{
		/** @var array $config */
		$config = $this->getConfig();

		echo "NOTIFY: ", $message, PHP_EOL;

		if ($config['notifyOwner']) {
			/** @var API $api */
			$api = $this->getApi();
			$api->messages->sendMessage(['peer' => $config['owner'], 'message' => $message]);
		}
	}

	public function shutdown(bool $save = true): void
	{
		if ($this->shuttingDown) {
			return;
		}
		$this->shuttingDown = true;

		if ($save) {
			echo "Performing final save before shutting down...", PHP_EOL;
			$this->save();
		}

		try {
			$this->notify('Bot is shutting down.');
		} catch (Throwable $ex) {
			echo "Failed to notify bot owner of shutdown: ", $ex->getMessage(), PHP_EOL;
		}

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

		pcntl_async_signals(true);
		/** @var int $signo */
		foreach ($this->getHandledSignals() as $signo) {
			if (!pcntl_signal($signo, [$this, 'onSignal'])) {
				echo "Warning: failed to attach signal handler for signal $signo", PHP_EOL;
			}
		}
		
		$this->save();

		if ($config['scanOnStart']) {
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
								'pwr' => null //$api->get_pwr_chat($chat, true, false),
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
				//echo "Users in channel#$channelId: ", implode(', ', array_column($users, 'first_name')), PHP_EOL;
			}
		}

		try {
			/** @var Throwable|null $ex Stores an exception across calls to runOnce. */
			$ex = null;

			while ($this->runOnce($ex)) {
				echo "Re-run requested.", PHP_EOL;
			}
		} finally {
			try {
				$this->shutdown(true);
			} catch (Throwable $ex) {
				echo "Failed to shut down cleanly: ", $ex->getMessage(), PHP_EOL;
			}
		}
	}

	/**
	 * @param Throwable|null &$exception Stores an excpetion across calls.
	 * @return bool true to request a re-run; otherwise, false. Used to recover from otherwise-fatal errors such as connectivity loss.
	 */
	private function runOnce(?Throwable &$exception = null): bool
	{
		/** @var API $api */
		$api = $this->getApi();

		if ($exception !== null) {
			// Perform this notification outside the main try-catch.  That way, if we encounter repeated exceptions (e.g., due to loss of connectivity), when the notification finally goes through, the initial exception will be reported.
			$this->notify("Bot restarted due to an unrecoverable exception:\n{$exception->getMessage()}");
		}

		try {
			// Only notify if there wasn't an exception; otherwise, we've already notified.
			if ($exception === null) {
				$this->notify("Bot online.");
			} else {
				// We know the exception has been reported by this point, so clear it.
				$exception = null;
			}

			$api->loop();
		} catch (MadelineException $ex) {
			$exception = $ex;
			/** @var string $message */
			$message = $ex->getMessage();
			
			/** @var string $prefix */
			foreach (self::RETRY_EXCEPTION_PREFIXES as $prefix) {
				if (substr_compare($message, $prefix, 0, strlen($prefix)) === 0) {
					return true;
				}
			}

			/** @var string $match */
			foreach (self::RETRY_EXCEPTION_MATCHES as $match) {
				if (strpos($message, $match) !== false) {
					return true;
				}
			}
		} catch (Throwable $ex) {
			$exception = $ex;
		}

		echo "Fatal exception:", PHP_EOL, $exception, PHP_EOL;

		return false;
	}

	/**
	 * @return array<string, int>
	 */
	private function getBadNames(): array
	{
		return array_flip($this->getConfig()['badNames']);
	}

	/**
	 * @return string[]
	 */
	private function getBadNameRegexes(): array
	{
		return $this->getConfig()['badNameRegexes'];
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
		/** @var string[] $badNameRegexes */
		$badNameRegexes = $this->getBadNameRegexes();
		/** @var API $api */
		$api = $this->getApi();
		/** @var array $config */
		$config = $this->getConfig();

		/** @var int $user */
		foreach ($users as $user) {
			/** @var bool $bad */
			$bad = false;
			/** @var string[] $reasons */
			$reasons = [];
			/** @var array<string, string> $testVals */
			$testVals = [];

			/** @var string $field */
			foreach ($config['testFields'] as $field) {
				if (isset($user[$field])) {
					$testVals[$field] = $user[$field];
				}
			}

			/** @var string $field */
			/** @var string $val */
			foreach ($testVals as $field => $val) {
				if (array_key_exists($val, $badNames)) {
					$bad = true;
					$reasons[] = "$field is a blacklisted name";
				}

				/** @var string $regex */
				foreach ($badNameRegexes as $regex) {
					if (preg_match($regex, $val)) {
						$bad = true;
						$reasons[] = "$field matches regex $regex";
					}
				}
			}

			if ($bad) {
				/** @var int $userId */
				$userId = $user['id'];

				/** @var string $displayUsername */
				$displayUsername = isset($user['username']) ? '@' . $user['username'] : 'no username';
				$this->notify("Banning user#$userId ($displayUsername) from channel#$channelId\nReason(s):\n - " . implode("\n - ", $reasons));

				if (!$this->isDryRun()) {
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

				if (!empty($messageIds[$userId]) || !empty($messageIds[''])) {
					if ($config['deleteDelay']) {
						sleep((int) $config['deleteDelay']);
					}

					/** @var int[] $messageIds */
					$messageIds = array_unique(array_merge($messageIds[$userId] ?? [], $messageIds[''] ?? []));
					$this->notify("Deleting messages from channel#$channelId: " . implode(', ', $messageIds));
					if (!$this->isDryRun()) {
						$api->channels->deleteMessages(['channel' => $inputChannel, 'id' => $messageIds]);
					}
				}
			}
		}
	}
}
