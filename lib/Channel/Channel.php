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

namespace OCA\DocumentServer\Channel;

use OCA\DocumentServer\XHRCommand\CommandDispatcher;
use OCP\Files\NotFoundException;
use OCA\DocumentServer\IPC\IIPCChannel;

class Channel {
	public const TYPE_OPEN = 'o';
	public const TYPE_HEARTBEAT = 'h';
	public const TYPE_ARRAY = 'a';
	public const TYPE_CLOSE = 'c';

	public const TIMEOUT = 25;

	/**
	 * How often a session waiting on its long poll says it is still there.
	 *
	 * Without this a session is only marked as seen when a poll starts, so a
	 * client that is very much present can be 25 seconds "unseen" against a 30
	 * second expiry - and an expired session is taken for a departed one, which
	 * now means the document is written out and closed under whoever is still
	 * editing it.
	 */
	public const SEEN_INTERVAL = 5;

	private $sessionId;
	private $documentId;
	private $sessionChannel;
	private $documentChannel;
	private $state;
	private $commandDispatcher;
	private $sessionManager;
	private $initialResponses = [];

	public function __construct(
		string $sessionId,
		int $documentId,
		IIPCChannel $sessionChannel,
		IIPCChannel $documentChannel,
		CommandDispatcher $commandDispatcher,
		SessionManager $sessionManager,
		array $initialResponses = []
	) {
		$this->sessionId = $sessionId;
		$this->documentId = $documentId;
		$this->sessionChannel = $sessionChannel;
		$this->documentChannel = $documentChannel;
		$this->commandDispatcher = $commandDispatcher;
		$this->initialResponses = $initialResponses;
		$this->sessionManager = $sessionManager;
	}

	public function getResponse() {
		$session = $this->sessionManager->getSession($this->sessionId);

		$this->sessionManager->markAsSeen($this->sessionId);

		if (!$session) {
			$this->sessionManager->newSession($this->sessionId, $this->documentId);
			foreach ($this->initialResponses as $initialResponse) {
				$this->sessionChannel->pushMessage(json_encode($initialResponse));
			}

			return [self::TYPE_OPEN, null];
		} else {
			$start = time();
			$lastSeen = $start;
			while ((time() - $start) < self::TIMEOUT) {
				$message = $this->sessionChannel->popMessage(self::TIMEOUT);
				if ($message) {
					return [self::TYPE_ARRAY, json_decode($message, true)];
				}

				if ((time() - $lastSeen) >= self::SEEN_INTERVAL) {
					$this->sessionManager->markAsSeen($this->sessionId);
					$lastSeen = time();
				}

				usleep(100 * 1000);
			}

			$session = $this->sessionManager->getSession($this->sessionId);

			if ($session) {
				$this->commandDispatcher->idleWork($session, $this->sessionChannel, $this->documentChannel);
			}

			return [self::TYPE_HEARTBEAT, null];
		}
	}

	public function handleCommand(array $command) {
		$session = $this->sessionManager->getSession($this->sessionId);

		if ($session) {
			$this->sessionManager->markAsSeen($session->getSessionId());
		} else {
			throw new NotFoundException("session not found");
		}

		$this->commandDispatcher->handle($command, $session, $this->sessionChannel, $this->documentChannel);
	}
}
