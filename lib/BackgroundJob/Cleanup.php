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
use OCP\ILogger;

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
		ILogger $logger
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
					$this->logger->logException($e, ['app' => 'documentserver_community', 'message' => 'Error while applying changes for document ' . $documentId]);
				}
			}
		}
	}
}
