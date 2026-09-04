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
use OCP\Lock\ILockingProvider;

class SaveHandler {
	private $documentStore;
	private $changeStore;
	private $documentConverter;
	private $lockingProvider;
	private $sessionManager;

	public function __construct(
		DocumentStore $documentStore,
		ChangeStore $changeStore,
		DocumentConverter $documentConverter,
		ILockingProvider $lockingProvider,
		SessionManager $sessionManager
	) {
		$this->documentStore = $documentStore;
		$this->changeStore = $changeStore;
		$this->documentConverter = $documentConverter;
		$this->lockingProvider = $lockingProvider;
		$this->sessionManager = $sessionManager;
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
	 * So a snapshot reads the changes and leaves them alone. The eventual
	 * flush replays the same list against the same baseline and produces the
	 * same document, which makes writing one out early free of consequences.
	 */
	public function saveSnapshot(int $documentId) {
		$this->lockingProvider->acquireLock('documentserver_' . $documentId, ILockingProvider::LOCK_EXCLUSIVE);

		try {
			$changes = $this->changeStore->getChangesForDocument($documentId);

			if (count($changes)) {
				$this->documentStore->saveChanges($documentId, $changes);
			}
		} finally {
			$this->lockingProvider->releaseLock('documentserver_' . $documentId, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function flushChanges(int $documentId) {
		$this->lockingProvider->acquireLock('documentserver_' . $documentId, ILockingProvider::LOCK_EXCLUSIVE);

		try {
			$changes = $this->changeStore->getChangesAndMarkProcessingForDocument($documentId);

			if (count($changes)) {
				$this->documentStore->saveChanges($documentId, $changes);

				$this->changeStore->deleteProcessedChanges($documentId);
			}

			if (!$this->sessionManager->isDocumentActive($documentId)) {
				$this->documentStore->closeDocument($documentId);
			}
		} catch (\Exception $e) {
			$this->changeStore->unmarkProcessing($documentId);
			throw $e;
		} finally {
			$this->lockingProvider->releaseLock('documentserver_' . $documentId, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
