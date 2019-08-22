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
