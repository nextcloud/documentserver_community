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
use OCA\DocumentServer\Document\Change;
use OCA\DocumentServer\Document\ChangeStore;
use OCA\DocumentServer\Document\Lock;
use OCA\DocumentServer\Document\LockStore;
use OCA\DocumentServer\IPC\IIPCChannel;
use OCP\AppFramework\Utility\ITimeFactory;

class SaveChangesCommand implements ICommandHandler {
	private $changeStore;
	private $lockStore;
	private $timeFactory;

	public function __construct(ChangeStore $changeStore, LockStore $lockStore, ITimeFactory $timeFactory) {
		$this->changeStore = $changeStore;
		$this->lockStore = $lockStore;
		$this->timeFactory = $timeFactory;
	}

	public function getType(): string {
		return 'saveChanges';
	}

	/**
	 * A save may arrive split over several messages: sdkjs slices its change
	 * array into chunks of at most websocketMaxPayloadSize (1.5 MB) and flags
	 * the first and last one. Absent flags mean a single, complete message.
	 */
	private static function isFirstChunk(array $command): bool {
		return !isset($command['startSaveChanges']) || (bool)$command['startSaveChanges'];
	}

	private static function isLastChunk(array $command): bool {
		return !isset($command['endSaveChanges']) || (bool)$command['endSaveChanges'];
	}

	public function handle(array $command, Session $session, IIPCChannel $sessionChannel, IIPCChannel $documentChannel, CommandDispatcher $commandDispatcher): void {
		$changes = json_decode($command['changes']);

		$isFirstChunk = self::isFirstChunk($command);
		$isLastChunk = self::isLastChunk($command);

		// deleteIndex is repeated on every chunk of the same save, and it is
		// relative to the change store as it was before the save started, so
		// re-applying it once we have stored a chunk would delete that chunk.
		if ($isFirstChunk && $command['deleteIndex']) {
			$this->changeStore->deleteChangesByIndex($session->getDocumentId(), (int)$command['deleteIndex']);
		}

		$startIndex = $this->changeStore->getMaxChangeIndexForDocument($session->getDocumentId());

		$this->changeStore->addChangesForDocument($session->getDocumentId(), $changes, $session->getUserId(), $session->getUserOriginal());

		$changeIndex = $this->changeStore->getMaxChangeIndexForDocument($session->getDocumentId());

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
			// Relayed, not synthesised: for spreadsheets this carries the
			// recalcIndexRows/recalcIndexColumns the receiver needs to shift
			// other users' locks after a row or column insert, and for text
			// documents it carries the sender's cursor position, which is how
			// foreign cursors move during fast co-editing.
			'excelAdditionalInfo' => $command['excelAdditionalInfo'] ?? null,
			// Both flags have to be relayed: the receiving sdkjs buffers the
			// changes of every message whose endSaveChanges is not true and
			// only applies the batch once it sees the closing one. Sending the
			// broadcast without them means every remote change is buffered
			// forever, so nothing a co-author types ever appears.
			'startSaveChanges' => $isFirstChunk,
			'endSaveChanges' => $isLastChunk,
		]));

		if (!$isLastChunk) {
			// Mid-save: the client waits for savePartChanges before sending the
			// next chunk, and the locks stay held until the save completes.
			$sessionChannel->pushMessage('{"type":"savePartChanges","changesIndex":' . $changeIndex . '}');
			return;
		}

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
