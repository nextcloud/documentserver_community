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
use OCA\DocumentServer\OnlyOffice\URLDecoder;
use OCP\IPC\IIPCChannel;
use OCP\IURLGenerator;
use function Sabre\HTTP\encodePathSegment;

class AuthCommand implements ICommandHandler {
	private $urlGenerator;
	private $changeStore;
	private $sessionManager;
	private $documentStore;
	private $urlDecoder;

	public function __construct(
		IURLGenerator $urlGenerator,
		ChangeStore $changeStore,
		SessionManager $sessionManager,
		DocumentStore $documentStore,
		URLDecoder $urlDecoder
	) {
		$this->urlGenerator = $urlGenerator;
		$this->changeStore = $changeStore;
		$this->sessionManager = $sessionManager;
		$this->documentStore = $documentStore;
		$this->urlDecoder = $urlDecoder;
	}

	public function getType(): string {
		return 'auth';
	}

	public function handle(array $command, Session $session, IIPCChannel $channel, CommandDispatcher $commandDispatcher): void {
		$changes = $this->changeStore->getChangesForDocument($session->getDocumentId());

		$channel->pushMessage(json_encode([
			'type' => 'authChanges',
			'changes' => array_map(function (Change $change) {
				return $change->formatForClient();
			}, $changes),
		]));

		$user = $command['user'];

		$this->sessionManager->newSession($session->getSessionId(), $session->getDocumentId(), $user['id'], $user['id']);

		$channel->pushMessage(json_encode([
			'type' => 'auth',
			'result' => 1,
			'sessionId' => $session->getSessionId(),
			'sessionTimeConnect' => time(),
			'participants' => [
				'id' => $user['id'],
				'idOriginal' => $user['id'],
				'username' => $user['username'],
				'indexUser' => 1,
				'view' => true,
				'connectionId' => $session->getSessionId(),
				'isCloseCoAuthoring' => false,
			],
			'locks' => [],
			'indexUser' => 1,
			'g_cAscSpellCheckUrl' => '/spellchecker',
			'buildVersion' => '5.3.2',
			'buildNumber' => 20,
			'licenseType' => 3,
			'settings' => [
				'spellcheckerUrl' => '/spellchecker',
				'reconnection' => [
					'attempts' => 50,
					'delay' => 2000,
				],
			],
		]));


		if (isset($command['openCmd'])) {
			$openCmd = $command['openCmd'];
			$docId = (int)$command['docid'];
			$documentUrl = $openCmd['url'];
			$inputFormat = $openCmd['format'];

			$documentFile = $this->urlDecoder->getFileForUrl($documentUrl);
			$this->documentStore->getDocumentForEditor($docId, $documentFile, $inputFormat);

			$files = array_merge(['Editor.bin'], $this->documentStore->getEmbeddedFiles($docId));
			$urls = array_map(function(string $file) use ($docId) {
				return $this->urlGenerator->linkToRouteAbsolute(
					'documentserver.Document.documentFile', [
						'path' => $file,
						'docId' => $docId,
					]
				);
			}, $files);

			$channel->pushMessage(json_encode([
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
