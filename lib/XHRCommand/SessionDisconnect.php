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

namespace OCA\DocumentServer\XHRCommand;


use OCA\DocumentServer\Channel\Session;
use OCA\DocumentServer\Channel\SessionManager;
use OCA\DocumentServer\IPC\IIPCChannel;

class SessionDisconnect implements IIdleHandler {
	private $sessionManager;

	public function __construct(SessionManager $sessionManager) {
		$this->sessionManager = $sessionManager;
	}


	public function handle(Session $session, IIPCChannel $sessionChannel, IIPCChannel $documentChannel, CommandDispatcher $commandDispatcher): void {
		$deleted = $this->sessionManager->cleanSessions();

		if ($deleted) {
			$participants = $this->sessionManager->getSessionsForDocument($session->getDocumentId());
			$message = json_encode([
				'type' => 'connectState',
				'participantsTimestamp' => time() * 1000,
				'participants' => array_map(function (Session $session) {
					return $session->formatForClient();
				}, $participants),
				'waitAuth' => false,
			]);
			$documentChannel->pushMessage($message);
			$sessionChannel->pushMessage($message);
		}
	}
}
