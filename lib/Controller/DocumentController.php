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

namespace OCA\DocumentServer\Controller;

use OCA\DocumentServer\FileResponse;
use OCA\DocumentServer\OnlyOffice\URLDecoder;
use OCA\DocumentServer\XHRCommand\AuthCommand;
use OCA\DocumentServer\XHRCommand\GetLock;
use OCA\DocumentServer\XHRCommand\IsSaveLock;
use OCA\DocumentServer\XHRCommand\SaveChangesCommand;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Channel\ChannelFactory;
use OCA\DocumentServer\XHRCommand\SessionDisconnect;
use OCA\DocumentServer\XHRCommand\UnlockDocument;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

class DocumentController extends SessionController {
	const INITIAL_RESPONSES = [
		'type' => 'license',
		'license' => [
			'type' => 3,
			'light' => false,
			'mode' => 0,
			'rights' => 1,
			'buildVersion' => '5.3.2',
			'buildNumber' => 20,
			'branding' => false,
			'customization' => false,
			'plugins' => false,
		],
	];

	const COMMAND_HANDLERS = [
		AuthCommand::class,
		IsSaveLock::class,
		SaveChangesCommand::class,
		GetLock::class,
		UnlockDocument::class,
	];

	const IDLE_HANDLERS = [
		SessionDisconnect::class,
	];

	/** @var DocumentStore */
	private $documentStore;

	private $urlDecoder;

	private $urlGenerator;

	public function __construct(
		$appName,
		IRequest $request,
		ChannelFactory $sessionFactory,
		DocumentStore $documentStore,
		ISecureRandom $random,
		URLDecoder $urlDecoder,
		IURLGenerator $urlGenerator
	) {
		parent::__construct($appName, $request, $sessionFactory, $random);

		$this->documentStore = $documentStore;
		$this->urlDecoder = $urlDecoder;
		$this->urlGenerator = $urlGenerator;
	}


	protected function getInitialResponses(): array {
		return self::INITIAL_RESPONSES;
	}

	protected function getCommandHandlerClasses(): array {
		return self::COMMAND_HANDLERS;
	}

	protected function getIdleHandlerClasses(): array {
		return self::IDLE_HANDLERS;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function healthCheck() {
		return true;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function documentFile(int $docId, string $path) {
		$file = $this->documentStore->openDocumentFile($docId, $path);

		return new FileResponse(
			$file->fopen('r'),
			$file->getSize(),
			$file->getMTime(),
			$file->getMimeType()
		);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function upload(int $docId, string $index) {
		$content = fopen('php://input', 'r');
		$mime = $this->request->getHeader('Content-Type');
		list(, $extension) = explode('/', $mime);
		$path = "media/$index.$extension";
		$this->documentStore->saveDocumentFile($docId, $path, $content);

		return new DataResponse([
			$path => $this->urlGenerator->linkToRouteAbsolute(
				'documentserver.Document.documentFile', [
					'path' => $path,
					'docId' => $docId,
				]
			),
		]);
	}
}
