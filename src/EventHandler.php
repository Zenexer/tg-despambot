<?php
declare(strict_types=1);

namespace Zenexer\Telegram\Bot;

use danog\MadelineProto\API;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\EventHandler as BaseEventHandler;

class EventHandler extends BaseEventHandler
{
	private const RECENT_THRESHOLD = -60;
	private const FUTURE_THRESHOLD = 5;

	/** @var MTProto */
	private $mtProto;

	public function __construct(MTProto $mtProto)
	{
		parent::__construct($mtProto);

		$this->mtProto = $mtProto;
	}

	private function getMTProto(): MTProto
	{
		return $this->mtProto;
	}

	public function isUpdateRecent(array $update): bool
	{
		$now = time();
		$ts = (int) $update['message']['date'];
		$delta = $ts - $now;
		$recent = true;

		if ($delta > self::FUTURE_THRESHOLD) {
			echo "Warning: update from the future.  Timestamp: $ts; Now: $now; Delta: $delta", PHP_EOL;
			$recent = false;
		}

		if ($delta < self::RECENT_THRESHOLD) {
			echo "Warning: update from the past.  Timestamp: $ts; Now: $now; Delta: $delta", PHP_EOL;
			$recent = false;
		}

		return $recent;
	}

	public function onUpdateNewChannelMessage(array $update): void
	{
		if (!$this->isUpdateRecent($update)) {
			return;
		}

		/** @var array $message */
		$message = $update['message'];

		if (array_key_exists('action', $message)) {
			$action = $message['action'];

			switch ($action['_']) {
				case 'messageActionChatAddUser':
					$this->onMessageActionChatAddUser($message, $action);
					break;

				case 'messageActionDeleteAddUser':
					$this->onMessageActionChatAddUser($message, $action);
					break;
			}
		}

		//echo "Channel message: ", json_encode($update, JSON_PRETTY_PRINT), PHP_EOL;
	}

	public function onUpdateNewMessage(array $update): void
	{
		if (!$this->isUpdateRecent($update)) {
			return;
		}

		//echo "Message: ", json_encode($update, JSON_PRETTY_PRINT), PHP_EOL;
	}

	public function onUpdateChannel(array $update): void
	{
		//echo "Channel update: ", json_encode($update, JSON_PRETTY_PRINT), PHP_EOL;
	}

	private function getApi(): API
	{
		return App::getInstance()->getApi();
	}

	public function onMessageActionChatAddUser(array $message, array $action): void
	{
		/** @var int[] $userIds */
		$userIds = $action['users'] ?? [];
		/** @var array $channel */
		$channel = $message['to_id'];
		/** @var int $channelId */
		$channelId = (int) $channel['channel_id'];
		/** @var App $app */
		$app = App::getInstance();

		$app->checkUsersByIds($message, $channelId, $userIds, ['' => [$message['id']]]);
	}

	public function onMessageActionChatDeleteUser(array $message, array $action): void
	{
		/** @var int $userId */
		$userId = $action['user_id'];
		/** @var array $channel */
		$channel = $message['to_id'];
		/** @var int $channelId */
		$channelId = (int) $channel['channel_id'];
		/** @var App $app */
		$app = App::getInstance();

		$app->checkUsersByIds($message, $channelId, [$userId], ['' => [$message['id']]]);
	}
}
