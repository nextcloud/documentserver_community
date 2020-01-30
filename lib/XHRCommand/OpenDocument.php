<?php declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Robin Appelman <robin@icewind.nl>
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
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\Document\PasswordRequiredException;
use OCA\DocumentServer\IPC\IIPCChannel;
use OCA\DocumentServer\OnlyOffice\URLDecoder;
use OCP\ISession;
use OCP\IURLGenerator;

class OpenDocument implements ICommandHandler {
	private $urlGenerator;
	private $documentStore;
	private $urlDecoder;
	private $session;

	public function __construct(
		IURLGenerator $urlGenerator,
		DocumentStore $documentStore,
		URLDecoder $urlDecoder,
		ISession $session
	) {
		$this->urlGenerator = $urlGenerator;
		$this->documentStore = $documentStore;
		$this->urlDecoder = $urlDecoder;
		$this->session = $session;
	}

	public function getType(): string {
		return 'openDocument';
	}

	public function handle(array $command, Session $session, IIPCChannel $sessionChannel, IIPCChannel $documentChannel, CommandDispatcher $commandDispatcher): void {
		$type = $command['message']['c'];

		if ($type === 'pathurls') {
			$requestPaths = $command['message']['data'];

			$paths = array_map(function ($path) use ($session) {
				return $this->urlGenerator->linkToRouteAbsolute(
					'documentserver_community.Document.documentFile', [
						'path' => $path,
						'docId' => $session->getDocumentId(),
					]
				);
			}, $requestPaths);

			$message = json_encode([
				'type' => 'documentOpen',
				'data' => [
					'status' => 'ok',
					'type' => 'pathurls',
					'data' => $paths,
				],
			]);

			$sessionChannel->pushMessage($message);
		} else if ($type === 'reopen' || $type === 'open') {
			$this->openDocument($command['message'], $sessionChannel);
		}

	}

	public function openDocument(array $openCmd, IIPCChannel $sessionChannel) {
		$docId = (int)$openCmd['id'];
		$documentUrl = $openCmd['url'] ?? null;
		$inputFormat = $openCmd['format'];
		$password = $openCmd['password'] ?? null;
		$command = $openCmd['c'];

		if (!$documentUrl) {
			$documentUrl = $this->documentStore->getStashedDocumentUrl($docId);
		}

		$documentFile = $this->urlDecoder->getFileForUrl($documentUrl);
		try {
			$this->documentStore->getDocumentForEditor($docId, $documentFile, $inputFormat, $password);
		} catch (PasswordRequiredException $e) {
			$this->documentStore->stashDocumentUrl($docId, $documentUrl);
			$sessionChannel->pushMessage(json_encode([
				'type' => 'documentOpen',
				'data' => [
					'type' => 'open',
					'status' => 'needpassword',
					'data' => -$e->getStatus(),
				],
			]));
			return;
		}

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
				'type' => $command,
				'status' => 'ok',
				'data' => array_combine($files, $urls),
			],
		]));
	}
}
