<?php

declare(strict_types=1);
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
use OCP\Http\Client\IClientService;
use OCP\ISession;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class OpenDocument implements ICommandHandler {
	private $urlGenerator;
	private $documentStore;
	private $urlDecoder;
	private $session;
	private $clientService;
	private $logger;

	public function __construct(
		IURLGenerator $urlGenerator,
		DocumentStore $documentStore,
		URLDecoder $urlDecoder,
		ISession $session,
		IClientService $clientService,
		LoggerInterface $logger
	) {
		$this->urlGenerator = $urlGenerator;
		$this->documentStore = $documentStore;
		$this->urlDecoder = $urlDecoder;
		$this->session = $session;
		$this->clientService = $clientService;
		$this->logger = $logger;
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
		} elseif ($type === 'reopen' || $type === 'open') {
			$this->openDocument($command['message'], $sessionChannel);
		} elseif ($type === 'imgurls') {
			$this->imageUrls($command['message'], $session, $sessionChannel, $documentChannel);
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

	public function imageUrls(array $command, Session $session, IIPCChannel $sessionChannel, IIPCChannel $documentChannel) {
		$error = 0;
		$urls = array_map(function ($inputUrl) use ($session, &$error) {
			$path = 'media/' . md5($inputUrl) . '.png';
			$data = $this->fetchImage((string)$inputUrl);
			if ($data) {
				$this->documentStore->saveDocumentFile($session->getDocumentId(), $path, $data);

				return [
					'url' => $this->urlGenerator->linkToRouteAbsolute(
						'documentserver_community.Document.documentFile', [
							'path' => $path,
							'docId' => $session->getDocumentId(),
						]
					),
					'path' => $path,
				];
			} else {
				$error = -104;
				return [
					'url' => 'error',
					'path' => 'error'
				];
			}
		}, $command['data']);

		$sessionChannel->pushMessage(json_encode([
			'type' => 'documentOpen',
			'data' => [
				'type' => 'imgurls',
				'status' => 'ok',
				'data' => [
					'error' => $error,
					'urls' => $urls,
				],
			],
		]));
	}

	/**
	 * Fetch an image the editor asked the server to import.
	 *
	 * The URL is whatever the client put in the command, so this is a request
	 * the server makes on someone else's say-so and it has to be treated as
	 * hostile: a bare fopen() here would happily read file:// paths, reach
	 * services on the server's own network, or hit a cloud metadata endpoint.
	 *
	 * Nextcloud's HTTP client is what makes it safe. It refuses a host that
	 * resolves to a private, loopback or link-local address, re-runs that check
	 * on every redirect target, and pins the connection to the address it
	 * validated so a second DNS answer cannot rebind it onto an internal one.
	 * Only the scheme is left for us to check, because the client passes
	 * anything else straight to curl.
	 *
	 * @return resource|null the response body, or null if the image could not
	 *                       be fetched
	 */
	private function fetchImage(string $inputUrl) {
		$scheme = strtolower((string)parse_url($inputUrl, PHP_URL_SCHEME));
		if ($scheme !== 'http' && $scheme !== 'https') {
			$this->logger->warning('Refused to fetch a document image over an unsupported scheme', [
				'scheme' => $scheme,
			]);
			return null;
		}

		try {
			$response = $this->clientService->newClient()->get($inputUrl, ['stream' => true]);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to fetch a document image', ['exception' => $e]);
			return null;
		}

		$body = $response->getBody();

		return is_resource($body) ? $body : null;
	}
}
