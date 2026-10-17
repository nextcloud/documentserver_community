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

namespace OCA\DocumentServer\Command;

use OC\Core\Command\Base;
use OCA\DocumentServer\Channel\SessionManager;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Document\SaveHandler;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Log\LoggerInterface;

class FlushChanges extends Base {
	private $saveHandler;
	private $documentStore;
	private $sessionManager;
	private $logger;

	public function __construct(
		SaveHandler $saveHandler,
		DocumentStore $documentStore,
		SessionManager $sessionManager,
		LoggerInterface $logger
	) {
		parent::__construct();

		$this->saveHandler = $saveHandler;
		$this->documentStore = $documentStore;
		$this->sessionManager = $sessionManager;
		$this->logger = $logger;
	}

	protected function configure() {
		$this
			->setName('documentserver:flush')
			->setDescription('Flush all pending changes made to documents')
			->addOption(
				'inactive-pages',
				null,
				InputOption::VALUE_NONE,
				'Flush only inactive pages'
			)
			->addOption(
				'snapshot',
				null,
				InputOption::VALUE_NONE,
				'Write every open document out to its file without ending any editing session'
			);
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$documents = $this->documentStore->getOpenDocuments();

		// One document that will not assemble - a converter that chokes on it,
		// a file the web server may not write - must not decide the fate of
		// every document after it in the list. Returning at the first failure
		// meant a single bad document left the rest unwritten, which is the
		// opposite of what somebody running this before a backup is asking for.
		// So each is attempted, and the exit code reports whether all of them
		// worked.
		$failed = 0;

		foreach ($documents as $documentId) {
			// A snapshot writes the file and leaves the editing session
			// running, which is the only safe thing to do to a document
			// somebody is still typing into. The background job does this on
			// its own schedule; the option is here to drive it from cron at a
			// chosen interval, or to get everything on disk on demand - before
			// a backup, say.
			if ($input->getOption('snapshot')) {
				try {
					$this->saveHandler->saveSnapshot($documentId);
				} catch (\Exception $e) {
					$failed++;
					$this->logger->error(
						'Error while saving a snapshot of document ' . $documentId,
						['exception' => $e, 'app' => 'documentserver_community']
					);
					$output->writeln("<error>could not save a snapshot of document $documentId: {$e->getMessage()}</error>");
				}
				continue;
			}

			if (!$input->getOption('inactive-pages') ||
			   !$this->sessionManager->isDocumentActive($documentId)) {
				try {
					// A document that is still being edited is written out
					// without its editing session being ended; see
					// SaveHandler::flushChanges().
					$this->saveHandler->flushChanges($documentId);
				} catch (\Exception $e) {
					$failed++;
					$this->logger->error(
						'Error while applying changes for document ' . $documentId,
						['exception' => $e, 'app' => 'documentserver_community']
					);
					$output->writeln("<error>could not flush document $documentId: {$e->getMessage()}</error>");
				}
			}
		}

		// the exit codes were the wrong way round, which makes the command
		// unusable from cron or a && chain
		return $failed === 0 ? 0 : 1;
	}
}
