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
use OCA\DocumentServer\Document\Change;
use OCA\DocumentServer\Document\ChangeStore;
use OCA\DocumentServer\Document\Lock;
use OCA\DocumentServer\Document\LockStore;
use OCA\DocumentServer\IPC\IIPCChannel;

class SaveChangesCommand implements ICommandHandler {
	private $changeStore;
	private $lockStore;

	public function __construct(ChangeStore $changeStore, LockStore $lockStore) {
		$this->changeStore = $changeStore;
		$this->lockStore = $lockStore;
	}

	public function getType(): string {
		return 'saveChanges';
	}

	public function handle(array $command, Session $session, IIPCChannel $sessionChannel, IIPCChannel $documentChannel, CommandDispatcher $commandDispatcher): void {
		$changes = json_decode($command['changes']);

		if ($command['deleteIndex']) {
			$this->changeStore->deleteChangesByIndex($session->getDocumentId(), (int)$command['deleteIndex']);
		}

		$startIndex = $this->changeStore->getMaxChangeIndexForDocument($session->getDocumentId());

		foreach ($changes as $change) {
			$this->changeStore->addChangeForDocument($session->getDocumentId(), $change, $session->getUserId(), $session->getUserOriginal());
		}

		$changeIndex = $this->changeStore->getMaxChangeIndexForDocument($session->getDocumentId());;

		$documentChannel->pushMessage(json_encode([
			'type' => 'saveChanges',
			'docId' => $session->getDocumentId(),
			'userId' => $session->getUserId(),
			'changes' => array_map(function (string $changeString) use ($session) {
				$change = new Change($session->getDocumentId(), time(), $changeString, $session->getUserId(), $session->getUserOriginal());
				return $change->formatForClient();
			}, $changes),
			'startIndex' => $startIndex,
			'changesIndex' => $changeIndex,
			'locks' => [],
			'excelAdditionalInfo' => '{}',
		]));

		if ($command["releaseLocks"]) {
			$released = $this->lockStore->releaseLocks($session->getDocumentId(), $session->getUserId());
			$locksMessage = json_encode([
				"type" => "releaseLock",
				"locks" => array_map(function (Lock $lock) {
					$data = $lock->jsonSerialize();
					$data['changes'] = null;
					return $data;
				}, $released),
			]);

			$documentChannel->pushMessage($locksMessage);
		}

		$now = time() * 1000;
		$sessionChannel->pushMessage('{"type":"unSaveLock","index":' . $changeIndex . ',"time":' . $now . '}');
	}
}
