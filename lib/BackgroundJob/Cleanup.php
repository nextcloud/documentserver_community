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

namespace OCA\DocumentServer\BackgroundJob;

use OCA\DocumentServer\Channel\SessionManager;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Document\LockStore;
use OCA\DocumentServer\Document\SaveHandler;
use OCA\DocumentServer\IPC\DatabaseIPCBackend;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\Job;
use Psr\Log\LoggerInterface;

class Cleanup extends Job {
	private $sessionManager;
	private $documentStore;
	private $saveHandler;
	private $lockStore;
	private $databaseIPCBackend;
	private $logger;

	public function __construct(
		ITimeFactory $time,
		SessionManager $sessionManager,
		DocumentStore $documentStore,
		SaveHandler $saveHandler,
		LockStore $lockStore,
		DatabaseIPCBackend $databaseIPCBackend,
		LoggerInterface $logger
	) {
		parent::__construct($time);

		$this->sessionManager = $sessionManager;
		$this->documentStore = $documentStore;
		$this->saveHandler = $saveHandler;
		$this->lockStore = $lockStore;
		$this->databaseIPCBackend = $databaseIPCBackend;
		$this->logger = $logger;
	}

	protected function run($argument) {
		$this->lockStore->expireLocks();
		$this->databaseIPCBackend->expireMessages();
		$this->sessionManager->cleanSessions();

		$documents = $this->documentStore->getOpenDocuments();
		foreach ($documents as $documentId) {
			if (!$this->sessionManager->isDocumentActive($documentId)) {
				try {
					$this->saveHandler->flushChanges($documentId);
				} catch (\Exception $e) {
					$this->logger->error(
						'Error while applying changes for document ' . $documentId, 
						['exception' => $e, 'app' => 'documentserver_community']
					);
				}
			} else {
				// Write the document out while it is still being edited. The
				// editor reporting "all changes are saved" means they reached
				// this server, not that they reached the file, and until this
				// existed the file was only written once the last participant
				// left - so a crash, or a browser closed at the wrong moment,
				// lost the session's work. A snapshot leaves the change list
				// alone, so the flush above still produces the same document
				// later; and it returns without running the converter when
				// nothing has been typed since the last one.
				try {
					$this->saveHandler->saveSnapshot($documentId);
				} catch (\Exception $e) {
					$this->logger->error(
						'Error while saving a snapshot of document ' . $documentId,
						['exception' => $e, 'app' => 'documentserver_community']
					);
				}
			}
		}
	}
}
