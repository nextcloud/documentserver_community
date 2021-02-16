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

use OCA\DocumentServer\IPC\IIPCChannel;
use OCA\DocumentServer\IPC\IIPCFactory;

/**
 * IPC Channel that sends to all other sessions connected for a document
 */
class IPCMulticast implements IIPCChannel {
	private $ipcFactory;
	private $sessionManager;
	private $documentId;
	private $sessionId;

	public function __construct(IIPCFactory $ipcFactory, SessionManager $sessionManager, int $documentId, string $sessionId) {
		$this->ipcFactory = $ipcFactory;
		$this->sessionManager = $sessionManager;
		$this->documentId = $documentId;
		$this->sessionId = $sessionId;
	}


	public function getName(): string {
		return "document_{$this->documentId}";
	}

	public function pushMessage(string $message) {
		$allSessions = $this->sessionManager->getSessionsForDocument($this->documentId);
		foreach ($allSessions as $session) {
			if ($session->getSessionId() !== $this->sessionId) {
				$channel = $this->ipcFactory->getChannel("session_" . $session->getSessionId());
				$channel->pushMessage($message);
			}
		}
	}

	public function popMessage(int $timeout): ?string {
		return null;
	}
}
