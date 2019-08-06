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

use OCA\DocumentServer\Command\CommandDispatcher;
use OCP\IMemcache;
use OCP\IPC\IIPCChannel;

class Channel {
	const TYPE_OPEN = 'o';
	const TYPE_HEARTBEAT = 'h';
	const TYPE_ARRAY = 'a';
	const TYPE_CLOSE = 'c';

	private $ipcChannel;
	private $state;
	private $commandDispatcher;
	private $sessionManager;
	private $initialResponses = [];

	public function __construct(
		IIPCChannel $ipcChannel,
		IMemcache $state,
		CommandDispatcher $commandDispatcher,
		SessionManager $sessionManager,
		array $initialResponses = []
	) {
		$this->ipcChannel = $ipcChannel;
		$this->state = $state;
		$this->commandDispatcher = $commandDispatcher;
		$this->initialResponses = $initialResponses;
		$this->sessionManager = $sessionManager;
	}

	public function getResponse() {
		$stateId = (int)($this->state->get('state') ?? 0);

		switch ($stateId) {
			case 0:
				$this->state->set('state', count($this->initialResponses) ? 1 : 2);
				return [self::TYPE_OPEN, null];
			case 1:
				$this->state->set('state', 2);
				return [self::TYPE_ARRAY, $this->initialResponses];
			default:
				$slept = 0;
				while ($slept < 25) {
					$message = $this->ipcChannel->popMessage();
					if ($message) {
						return [self::TYPE_ARRAY, json_decode($message, true)];
					}

					usleep(100 * 1000);
					$slept += 0.1;
				}
				return [self::TYPE_HEARTBEAT, null];
		}
	}

	public function handleCommand(array $command, int $documentId, string $sessionId) {
		$session = $this->sessionManager->getSession($sessionId);

		// create a fake session so we have document id and session id during the auth command handling
		if (!$session) {
			$session = new Session($sessionId, $documentId, '', '', time());
		}

		$this->commandDispatcher->handle($command, $session, $this->ipcChannel);
	}
}
