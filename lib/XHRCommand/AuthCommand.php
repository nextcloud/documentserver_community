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
use OCA\DocumentServer\Channel\SessionManager;
use OCA\DocumentServer\Document\Change;
use OCA\DocumentServer\Document\ChangeStore;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Document\LockStore;
use OCA\DocumentServer\OnlyOffice\URLDecoder;
use OCA\DocumentServer\IPC\IIPCChannel;
use OCA\DocumentServer\OnlyOffice\WebVersion;
use OCP\IURLGenerator;
use function Sabre\HTTP\encodePathSegment;

class AuthCommand implements ICommandHandler {
	const MAX_CONNECTIONS = 20;

	private $urlGenerator;
	private $changeStore;
	private $sessionManager;
	private $documentStore;
	private $urlDecoder;
	private $lockStore;
	private $webVersion;

	public function __construct(
		IURLGenerator $urlGenerator,
		ChangeStore $changeStore,
		SessionManager $sessionManager,
		DocumentStore $documentStore,
		URLDecoder $urlDecoder,
		LockStore $lockStore,
		WebVersion $webVersion
	) {
		$this->urlGenerator = $urlGenerator;
		$this->changeStore = $changeStore;
		$this->sessionManager = $sessionManager;
		$this->documentStore = $documentStore;
		$this->urlDecoder = $urlDecoder;
		$this->lockStore = $lockStore;
		$this->webVersion = $webVersion;
	}

	public function getType(): string {
		return 'auth';
	}

	public function handle(array $command, Session $session, IIPCChannel $sessionChannel, IIPCChannel $documentChannel, CommandDispatcher $commandDispatcher): void {
		$changes = $this->changeStore->getChangesForDocument($session->getDocumentId());

		$sessionChannel->pushMessage(json_encode([
			'type' => 'authChanges',
			'changes' => array_map(function (Change $change) {
				return $change->formatForClient();
			}, $changes),
		]));

		$user = $command['user'];
		$readOnly = $command['view'];

		$session = $this->sessionManager->authenticate($session, $user['id'], $user['id'], $user['username'], $readOnly);

		$participants = $this->sessionManager->getSessionsForDocument($session->getDocumentId());

		if (count($participants) > self::MAX_CONNECTIONS) {
			$sessionChannel->pushMessage(json_encode([
				'type' => 'auth',
				'result' => 0,
			]));

			return;
		}

		$sessionChannel->pushMessage(json_encode([
			'type' => 'auth',
			'result' => 1,
			'sessionId' => $session->getSessionId(),
			'sessionTimeConnect' => $session->getLastSeen(),
			'participants' => [[
				'id' => $session->getUserId(),
				'idOriginal' => $session->getUserOriginal(),
				'username' => $session->getUser(),
				'indexUser' => $session->getUserIndex(),
				'view' => $session->isReadOnly(),
				'connectionId' => $session->getSessionId(),
				'isCloseCoAuthoring' => false,
			]],
			'locks' => $this->lockStore->getLocksForDocument($session->getDocumentId()),
			'indexUser' => $session->getUserIndex(),
			'buildVersion' => $this->webVersion->getWebUIVersion(),
			'buildNumber' => 1,
			'licenseType' => 7,
			'settings' => [
				'reconnection' => [
					'attempts' => 50,
					'delay' => 2000,
				],
			],
		]));

		$message = json_encode([
			'type' => 'connectState',
			'participantsTimestamp' => time() * 1000,
			'participants' => array_map(function (Session $session) {
				return $session->formatForClient();
			}, $participants),
			'waitAuth' => false,
		]);
		$documentChannel->pushMessage($message);
		$sessionChannel->pushMessage($message);


		if (isset($command['openCmd'])) {
			$openCmd = $command['openCmd'];
			$docId = (int)$command['docid'];
			$documentUrl = $openCmd['url'];
			$inputFormat = $openCmd['format'];

			$documentFile = $this->urlDecoder->getFileForUrl($documentUrl);
			$this->documentStore->getDocumentForEditor($docId, $documentFile, $inputFormat);

			$files = array_merge(['Editor.bin'], $this->documentStore->getEmbeddedFiles($docId));
			$urls = array_map(function (string $file) use ($docId) {
				return $this->urlGenerator->linkToRouteAbsolute(
					'documentserver_community.Document.documentFile', [
						'path' => $file,
						'docId' => $docId,
					]
				);
			}, $files);

			$sessionChannel->pushMessage(json_encode([
				'type' => 'documentOpen',
				'data' => [
					'type' => 'open',
					'status' => 'ok',
					'data' => array_combine($files, $urls),
				],
			]));
		}
	}
}
