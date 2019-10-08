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
use OCA\DocumentServer\Document\LockStore;
use OCA\DocumentServer\IPC\IIPCChannel;

class GetLock implements ICommandHandler {
	private $lockStore;

	public function __construct(LockStore $lockStore) {
		$this->lockStore = $lockStore;
	}


	public function getType(): string {
		return 'getLock';
	}

	public function handle(array $command, Session $session, IIPCChannel $sessionChannel, IIPCChannel $documentChannel, CommandDispatcher $commandDispatcher): void {
		$blocks = $command['block'];

		foreach ($blocks as $block) {
			$this->lockStore->storeLock($session->getDocumentId(), $session->getUserId(), $block);
		}

		$allLocks = $this->lockStore->getLocksForDocument($session->getDocumentId());

		$lockMessage = json_encode([
			"type" => "getLock",
			"locks" => $allLocks
		]);
		$sessionChannel->pushMessage($lockMessage);
		$documentChannel->pushMessage($lockMessage);
	}
}
