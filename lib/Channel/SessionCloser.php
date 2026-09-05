<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2026 Fabrice Meyer <meyer.fabrice@gmx.fr>
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

namespace OCA\DocumentServer\Channel;

use OCA\DocumentServer\Document\SaveHandler;
use OCA\DocumentServer\IPC\IIPCFactory;
use Psr\Log\LoggerInterface;

/**
 * An editor that is gone: it said so itself, rather than being found out by a
 * session that stopped polling.
 *
 * This is the moment the document can be written: until the last participant
 * leaves it must not be, and once they have left there is nobody polling any
 * more, so the write used to wait for whenever the Cleanup background job
 * happened to run next. On an instance whose cron is slow, misconfigured or set
 * to AJAX, that was the difference between a document being saved and a day's
 * work sitting in a database table nobody looked at (#100).
 */
class SessionCloser {
	private $sessionManager;
	private $saveHandler;
	private $ipcFactory;
	private $logger;

	public function __construct(
		SessionManager $sessionManager,
		SaveHandler $saveHandler,
		IIPCFactory $ipcFactory,
		LoggerInterface $logger
	) {
		$this->sessionManager = $sessionManager;
		$this->saveHandler = $saveHandler;
		$this->ipcFactory = $ipcFactory;
		$this->logger = $logger;
	}

	public function sessionLeft(Session $session): void {
		$documentId = $session->getDocumentId();

		$this->sessionManager->removeSession($session->getSessionId());

		$participants = $this->sessionManager->getSessionsForDocument($documentId);

		if (count($participants)) {
			// Tell the people still in the document that this one left, rather
			// than leaving them with a ghost participant until the session
			// would have expired.
			$documentChannel = new IPCMulticast(
				$this->ipcFactory,
				$this->sessionManager,
				$documentId,
				$session->getSessionId()
			);
			$documentChannel->pushMessage(json_encode([
				'type' => 'connectState',
				'participantsTimestamp' => time() * 1000,
				'participants' => array_map(function (Session $participant) {
					return $participant->formatForClient();
				}, $participants),
				'waitAuth' => false,
			]));
			return;
		}

		// The last participant left, so write the document out here instead of
		// leaving it to the background job. Assembling it takes long enough
		// that the browser, which is on its way out of the page, may well drop
		// the request first; finish the save anyway, since nothing else is
		// going to.
		ignore_user_abort(true);

		try {
			$this->saveHandler->flushChanges($documentId);
		} catch (\Throwable $e) {
			// The changes are still in the store and the document folder is
			// still there, so the background job will try again. This must not
			// escape: on the command path it would fail the command, and on the
			// beacon path there is nobody left to see it.
			$this->logger->error('documentserver failed to save document {doc} when the last editor left', [
				'doc' => $documentId,
				'exception' => $e,
			]);
		}
	}
}
