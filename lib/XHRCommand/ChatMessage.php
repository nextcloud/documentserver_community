<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\DocumentServer\XHRCommand;

use OCA\DocumentServer\Channel\Session;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\IPC\IIPCChannel;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * One chat message from the editor's chat panel.
 *
 * The panel is on by default (the connector only sends
 * customization.chat when an admin switches it off), and without a handler
 * every message was dropped, so chat looked broken out of the box.
 *
 * The sender gets the message back too: the panel does not add what you type
 * to its own list, it waits to be told, and the document channel deliberately
 * skips the session it came from.
 */
class ChatMessage implements ICommandHandler {
	/** Long enough for a chunk of a message to be stored while another arrives. */
	private const LOCK_ATTEMPTS = 5;
	private const LOCK_RETRY_US = 50 * 1000;

	private $documentStore;
	private $lockingProvider;
	private $logger;

	public function __construct(
		DocumentStore $documentStore,
		ILockingProvider $lockingProvider,
		LoggerInterface $logger
	) {
		$this->documentStore = $documentStore;
		$this->lockingProvider = $lockingProvider;
		$this->logger = $logger;
	}

	public function getType(): string {
		return 'message';
	}

	public function handle(array $command, Session $session, IIPCChannel $sessionChannel, IIPCChannel $documentChannel, CommandDispatcher $commandDispatcher): void {
		if (!isset($command['message']) || !is_string($command['message'])) {
			return;
		}

		$message = [
			'user' => $session->getUserId(),
			'useridoriginal' => $session->getUserOriginal(),
			'username' => $session->getUserName(),
			'message' => $command['message'],
			'time' => time() * 1000,
		];

		// Appending is read-modify-write on one file, so it has to be
		// serialised against the other participants. Delivery still happens if
		// the history cannot be updated: losing a message from the backlog is
		// better than losing it from the conversation.
		$this->withHistoryLock($session->getDocumentId(), function () use ($session, $message) {
			$this->documentStore->addChatMessage($session->getDocumentId(), $message);
		});

		$payload = json_encode([
			'type' => 'message',
			'messages' => [$message],
		]);
		$sessionChannel->pushMessage($payload);
		$documentChannel->pushMessage($payload);
	}

	private function withHistoryLock(int $documentId, callable $work): void {
		$lock = 'documentserver_chat_' . $documentId;

		for ($attempt = 0; $attempt < self::LOCK_ATTEMPTS; $attempt++) {
			try {
				$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			} catch (LockedException $e) {
				usleep(self::LOCK_RETRY_US);
				continue;
			}

			try {
				$work();
			} catch (\Exception $e) {
				$this->logger->warning('documentserver could not store a chat message for document {doc}: {error}', [
					'doc' => $documentId,
					'error' => $e->getMessage(),
					'exception' => $e,
				]);
			} finally {
				$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			}
			return;
		}

		$this->logger->warning('documentserver gave up on the chat history lock for document {doc}', [
			'doc' => $documentId,
		]);
	}
}
