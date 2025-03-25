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

namespace OCA\DocumentServer\Controller;

use OCA\DocumentServer\Channel\SessionManager;
use OCA\DocumentServer\FileResponse;
use OCA\DocumentServer\IPC\IIPCFactory;
use OCA\DocumentServer\OnlyOffice\URLDecoder;
use OCA\DocumentServer\OnlyOffice\WebVersion;
use OCA\DocumentServer\XHRCommand\AuthCommand;
use OCA\DocumentServer\XHRCommand\CursorCommand;
use OCA\DocumentServer\XHRCommand\GetLock;
use OCA\DocumentServer\XHRCommand\IsSaveLock;
use OCA\DocumentServer\XHRCommand\LockExpire;
use OCA\DocumentServer\XHRCommand\SaveChangesCommand;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Channel\ChannelFactory;
use OCA\DocumentServer\XHRCommand\SessionDisconnect;
use OCA\DocumentServer\XHRCommand\UnlockDocument;
use OCA\DocumentServer\XHRCommand\OpenDocument;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

class DocumentController extends SessionController {
	public const COMMAND_HANDLERS = [
		AuthCommand::class,
		IsSaveLock::class,
		SaveChangesCommand::class,
		GetLock::class,
		UnlockDocument::class,
		CursorCommand::class,
		OpenDocument::class,
	];

	public const IDLE_HANDLERS = [
		SessionDisconnect::class,
		LockExpire::class,
	];

	/** @var DocumentStore */
	private $documentStore;
	private $urlDecoder;
	private $urlGenerator;
	private $webVersion;
	private $ipcFactory;
	private $sessionManager;

	public function __construct(
		$appName,
		IRequest $request,
		ChannelFactory $sessionFactory,
		DocumentStore $documentStore,
		ISecureRandom $random,
		URLDecoder $urlDecoder,
		IURLGenerator $urlGenerator,
		WebVersion $webVersion,
		IIPCFactory $ipcFactory,
		SessionManager $sessionManager
	) {
		parent::__construct($appName, $request, $sessionFactory, $random);

		$this->documentStore = $documentStore;
		$this->urlDecoder = $urlDecoder;
		$this->urlGenerator = $urlGenerator;
		$this->webVersion = $webVersion;
		$this->ipcFactory = $ipcFactory;
		$this->sessionManager = $sessionManager;
	}

	protected function getInitialResponses(): array {
		return [[
			'type' => 'license',
			'license' => [
				'type' => 3,
				'light' => false,
				'mode' => 0,
				'rights' => 1,
				'buildVersion' => $this->webVersion->getWebUIVersion(),
				'buildNumber' => 20,
				'branding' => false,
				'customization' => false,
				'plugins' => false,
			],
		]];
	}

	protected function getCommandHandlerClasses(): array {
		return self::COMMAND_HANDLERS;
	}

	protected function getIdleHandlerClasses(): array {
		return self::IDLE_HANDLERS;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function healthCheck() {
		return new DataResponse(true);
                //return true;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function documentFile(int $docId, string $path, ?bool $download) {
		$file = $this->documentStore->openDocumentFile($docId, $path);

		$response = new FileResponse(
			$file->fopen('r'),
			$file->getSize(),
			$file->getMTime(),
			$file->getMimeType(),
			$file->getName()
		);

		if ($download) {
			$response->setDownload();
		}
		return $response;
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function upload(int $docId, string $index) {
		$content = fopen('php://input', 'r');
		$mime = $this->request->getHeader('Content-Type');
		[, $extension] = explode('/', $mime);
		$path = "media/$index.$extension";
		$this->documentStore->saveDocumentFile($docId, $path, $content);

		$path = $this->urlGenerator->linkToRouteAbsolute(
			'documentserver_community.Document.documentFile', [
				'path' => $path,
				'docId' => $docId,
			]
		);

		return new DataResponse([
			$path => $path,
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function download(int $docId, string $cmd) {
		$cmd = json_decode($cmd, true);
		$content = fopen('php://input', 'r');
		$title = $this->documentStore->convertForDownload($docId, $content, $cmd);

		$session = $this->sessionManager->getSessionForUser($cmd['userconnectionid']);
		if ($session) {
			$key = $key = "session_" . $session->getSessionId();
			$sessionChannel = $this->ipcFactory->getChannel($key);

			$url = $this->urlGenerator->linkToRouteAbsolute(
				'documentserver_community.Document.documentFile', [
					'path' => $title,
					'docId' => $docId,
					'download' => 1,
				]
			);

			$sessionChannel->pushMessage(json_encode([
				'type' => 'documentOpen',
				'data' => [
					'type' => 'save',
					'status' => 'ok',
					'data' => $url,
				],
			]));
		}

		return new DataResponse([
			'type' => 'save',
			'status' => 'ok',
			'data' => $docId,
		]);
	}
}
