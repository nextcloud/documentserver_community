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

namespace OCA\DocumentServer\Document;

use OCA\DocumentServer\Channel\SessionManager;
use OCA\DocumentServer\DocumentConverter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class SaveHandler {
	/**
	 * How long flushing waits for a write that is already in progress, in
	 * seconds. The lock is only ever held for as long as the converter takes,
	 * and the caller that waits is usually the last editor leaving - the one
	 * moment where giving up means the document waits for a background job.
	 */
	private const LOCK_WAIT = 15;

	private $documentStore;
	private $changeStore;
	private $documentConverter;
	private $lockingProvider;
	private $sessionManager;
	private $timeFactory;

	public function __construct(
		DocumentStore $documentStore,
		ChangeStore $changeStore,
		DocumentConverter $documentConverter,
		ILockingProvider $lockingProvider,
		SessionManager $sessionManager,
		ITimeFactory $timeFactory
	) {
		$this->documentStore = $documentStore;
		$this->changeStore = $changeStore;
		$this->documentConverter = $documentConverter;
		$this->lockingProvider = $lockingProvider;
		$this->sessionManager = $sessionManager;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * Write the document out as it stands, leaving the editing session running.
	 *
	 * Not the same job as flushChanges(), which is for a session that is
	 * ending. saveChanges() replays the change list against the document's
	 * Editor.bin without rebasing it, so consuming the changes mid-session
	 * strands the document twice over: a session opened afterwards replays an
	 * empty list against the untouched baseline and shows the pre-save
	 * document, and the eventual flush writes the file from a change list that
	 * no longer holds the earlier edits, losing them.
	 *
	 * So a snapshot reads the changes and leaves them alone. The eventual flush
	 * replays the same list against the same baseline and produces the same
	 * document, which is what makes writing one out early free of consequence.
	 *
	 * @return bool whether the document was written
	 */
	public function saveSnapshot(int $documentId): bool {
		try {
			$this->lockingProvider->acquireLock('documentserver_' . $documentId, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException $e) {
			// somebody else is already writing this document out; theirs
			// covers everything ours would have
			return false;
		}

		try {
			return $this->writeDocument($documentId);
		} finally {
			$this->lockingProvider->releaseLock('documentserver_' . $documentId, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Take the document lock, waiting for a write that is already running.
	 *
	 * Nextcloud's locking does not block, so without this a write that is
	 * already assembling the document turns the flush behind it into a failure
	 * rather than a wait.
	 *
	 * @throws LockedException if the document is still being written after
	 *                         LOCK_WAIT seconds
	 */
	private function acquireLockWaiting(int $documentId): void {
		$deadline = $this->timeFactory->getTime() + self::LOCK_WAIT;

		while (true) {
			try {
				$this->lockingProvider->acquireLock('documentserver_' . $documentId, ILockingProvider::LOCK_EXCLUSIVE);
				return;
			} catch (LockedException $e) {
				if ($this->timeFactory->getTime() >= $deadline) {
					throw $e;
				}
				usleep(500 * 1000);
			}
		}
	}

	/**
	 * Write the assembled document to its file. Caller holds the document lock.
	 *
	 * The time is recorded before the converter runs as well as after it, so
	 * that a document which fails to assemble backs off instead of being
	 * retried by every writer that comes along.
	 */
	private function writeDocument(int $documentId): bool {
		$changeIndex = $this->changeStore->getMaxChangeIndexForDocument($documentId);
		$state = $this->documentStore->getSnapshotState($documentId);
		$now = $this->timeFactory->getTime();

		if ($changeIndex === $state['index']) {
			// nothing typed since the last write: the file is already current
			$this->documentStore->setSnapshotState($documentId, $changeIndex, $now);
			return false;
		}

		$this->documentStore->setSnapshotState($documentId, $state['index'], $now);

		$changes = $this->changeStore->getChangesForDocument($documentId);
		if (count($changes)) {
			$this->documentStore->saveChanges($documentId, $changes);
		}

		$this->documentStore->setSnapshotState($documentId, $changeIndex, $now);

		return true;
	}

	/**
	 * Write the document out and end its editing session: the change list is
	 * consumed and the document folder disposed of.
	 *
	 * Only safe once the last participant has left, because the change list is
	 * the only record of everything typed - Editor.bin stays at the version the
	 * document was opened at. So the state is checked again under the lock, and
	 * again after the converter has run, and a document that turned out to
	 * still be open is only written, never emptied.
	 */
	public function flushChanges(int $documentId) {
		$this->acquireLockWaiting($documentId);

		try {
			if ($this->sessionManager->isDocumentActive($documentId)) {
				$this->writeDocument($documentId);
				return;
			}

			$changeIndex = $this->changeStore->getMaxChangeIndexForDocument($documentId);
			$changes = $this->changeStore->getChangesAndMarkProcessingForDocument($documentId);

			if (count($changes)) {
				$this->documentStore->saveChanges($documentId, $changes);
			}

			if ($this->sessionManager->isDocumentActive($documentId)) {
				// Somebody opened the document while it was being assembled.
				// Their session is editing against the change list we just
				// replayed, so leave it, and the baseline it applies to, in
				// place: the file is written, and the next flush writes the
				// same document again.
				$this->changeStore->unmarkProcessing($documentId);
				$this->documentStore->setSnapshotState($documentId, $changeIndex, $this->timeFactory->getTime());
				return;
			}

			$this->changeStore->deleteProcessedChanges($documentId);
			$this->documentStore->closeDocument($documentId);
		} catch (\Exception $e) {
			$this->changeStore->unmarkProcessing($documentId);
			throw $e;
		} finally {
			$this->lockingProvider->releaseLock('documentserver_' . $documentId, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
