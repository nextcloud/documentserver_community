<?php declare(strict_types=1);
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
use OCP\IMemcache;
use OCA\DocumentServer\IPC\IIPCChannel;

class Channel {
	const TYPE_OPEN = 'o';
	const TYPE_HEARTBEAT = 'h';
	const TYPE_ARRAY = 'a';
	const TYPE_CLOSE = 'c';

	const TIMEOUT = 25;

	private $sessionChannel;
	private $documentChannel;
	private $state;
	private $commandDispatcher;
	private $sessionManager;
	private $initialResponses = [];

	public function __construct(
		IIPCChannel $sessionChannel,
		IIPCChannel $documentChannel,
		IMemcache $state,
		CommandDispatcher $commandDispatcher,
		SessionManager $sessionManager,
		array $initialResponses = []
	) {
		$this->sessionChannel = $sessionChannel;
		$this->documentChannel = $documentChannel;
		$this->state = $state;
		$this->commandDispatcher = $commandDispatcher;
		$this->initialResponses = $initialResponses;
		$this->sessionManager = $sessionManager;
	}

	public function getResponse($sessionId) {
		$stateId = (int)($this->state->get('state') ?? 0);

		$this->sessionManager->markAsSeen($sessionId);

		switch ($stateId) {
			case 0:
				$this->state->set('state', count($this->initialResponses) ? 1 : 2);
				return [self::TYPE_OPEN, null];
			case 1:
				$this->state->set('state', 2);
				return [self::TYPE_ARRAY, $this->initialResponses];
			default:
				$start = time();
				while ((time() - $start) < self::TIMEOUT) {
					$message = $this->sessionChannel->popMessage(self::TIMEOUT);
					if ($message) {
						return [self::TYPE_ARRAY, json_decode($message, true)];
					}

					usleep(100 * 1000);
				}

				$session = $this->sessionManager->getSession($sessionId);

				if ($session) {
					$this->commandDispatcher->idleWork($session, $this->sessionChannel, $this->documentChannel);
				}

				return [self::TYPE_HEARTBEAT, null];
		}
	}

	public function handleCommand(array $command, int $documentId, string $sessionId) {
		$session = $this->sessionManager->getSession($sessionId);

		if ($session) {
			$this->sessionManager->markAsSeen($session->getSessionId());
		} else {
			// create a fake session so we have document id and session id during the auth command handling
			$session = new Session($sessionId, $documentId, '', '', time(), false, 0);
		}

		$this->commandDispatcher->handle($command, $session, $this->sessionChannel, $this->documentChannel);
	}
}
