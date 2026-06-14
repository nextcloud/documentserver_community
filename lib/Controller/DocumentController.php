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

use OCA\DocumentServer\Channel\Channel;
use OCA\DocumentServer\Channel\ChannelFactory;
use OCA\DocumentServer\Channel\SessionManager;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\EngineIOResponse;
use OCA\DocumentServer\FileResponse;
use OCA\DocumentServer\IPC\IIPCFactory;
use OCA\DocumentServer\OnlyOffice\URLDecoder;
use OCA\DocumentServer\OnlyOffice\WebVersion;
use OCA\DocumentServer\XHRCommand\AuthCommand;
use OCA\DocumentServer\XHRCommand\CommandDispatcher;
use OCA\DocumentServer\XHRCommand\CursorCommand;
use OCA\DocumentServer\XHRCommand\GetLock;
use OCA\DocumentServer\XHRCommand\IsSaveLock;
use OCA\DocumentServer\XHRCommand\LockExpire;
use OCA\DocumentServer\XHRCommand\OpenDocument;
use OCA\DocumentServer\XHRCommand\SaveChangesCommand;
use OCA\DocumentServer\XHRCommand\SessionDisconnect;
use OCA\DocumentServer\XHRCommand\UnlockDocument;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class DocumentController extends Controller {
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

	private ChannelFactory $sessionFactory;
	/** @var DocumentStore */
	private $documentStore;
	private $urlDecoder;
	private $urlGenerator;
	private $webVersion;
	private $ipcFactory;
	private $sessionManager;
	private LoggerInterface $logger;

	public function __construct(
		$appName,
		IRequest $request,
		ChannelFactory $sessionFactory,
		DocumentStore $documentStore,
		URLDecoder $urlDecoder,
		IURLGenerator $urlGenerator,
		WebVersion $webVersion,
		IIPCFactory $ipcFactory,
		SessionManager $sessionManager,
		LoggerInterface $logger
	) {
		parent::__construct($appName, $request);

		$this->sessionFactory = $sessionFactory;
		$this->documentStore = $documentStore;
		$this->urlDecoder = $urlDecoder;
		$this->urlGenerator = $urlGenerator;
		$this->webVersion = $webVersion;
		$this->ipcFactory = $ipcFactory;
		$this->sessionManager = $sessionManager;
		$this->logger = $logger;
	}

	private function getCommandDispatcher(): CommandDispatcher {
		$dispatcher = new CommandDispatcher();
		foreach (self::COMMAND_HANDLERS as $class) {
			$dispatcher->addHandler(\OC::$server->query($class));
		}
		foreach (self::IDLE_HANDLERS as $class) {
			$dispatcher->addIdleHandler(\OC::$server->query($class));
		}
		return $dispatcher;
	}

	private function getInitialResponses(): array {
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
		$path = ltrim($path, '/');
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
			$key = $session->getSessionId();
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

	/**
	 * Engine.IO v4 long-poll GET handler (socket.io 4.x transport).
	 *
	 * Without ?sid: returns the EIO handshake (open packet).
	 * With ?sid:    long-polls for the next queued message and encodes it
	 *               as a socket.io EVENT or returns a noop on timeout.
	 *
	 * TYPE_OPEN is mapped to the socket.io CONNECT ack (packet "40"),
	 * which fires the client-side "connect" event.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function socketIOPoll(string $documentId): Response {
		$sid = $this->request->getParam('sid');
		$transport = $this->request->getParam('transport');

		// PHP cannot upgrade to WebSocket; return 400 so the client
		// falls back to HTTP long-polling on its own.
		if ($transport === 'websocket') {
			return new DataResponse(null, 400);
		}

		if (!$sid) {
			// Initial EIO handshake — generate a fresh session ID.
			// 8 bytes → 16 hex chars fits the existing VARCHAR(16) session_id column.
			$sid = bin2hex(random_bytes(8));
			return new EngineIOResponse('0' . json_encode([
				'sid' => $sid,
				'upgrades' => [],      // no WebSocket upgrade in PHP
				'pingInterval' => 25000,
				'pingTimeout' => 20000,
				'maxPayload' => 100000000,
			]));
		}

		$channel = $this->sessionFactory->getSession(
			$sid,
			$documentId,
			$this->getCommandDispatcher(),
			$this->getInitialResponses()
		);
		[$type, $data] = $channel->getResponse();

		switch ($type) {
			case Channel::TYPE_OPEN:
				// Socket.IO 4.x client requires {"sid":"..."} in the CONNECT ack.
				// Without it the client fires connect_error ("v2.x server") instead
				// of connect, so the sdkjs auth command is never sent.
				return new EngineIOResponse('40' . json_encode(['sid' => $sid]));

			case Channel::TYPE_ARRAY:
				// Wrap as socket.io EVENT: 4=EIO message, 2=sio event.
				// sdkjs >= 7.3 uses Socket.IO 4.x which delivers the argument
				// as a plain object, not a JSON string, so pass $data directly.
				return new EngineIOResponse('42' . json_encode(['message', $data]));

			case Channel::TYPE_CLOSE:
				return new EngineIOResponse('41');  // socket.io DISCONNECT

			case Channel::TYPE_HEARTBEAT:
				// EIO v4 reversed ping direction: server sends PING (2), client
				// responds PONG (3). Without this, the client's pingTimeoutTimer
				// fires after pingInterval+pingTimeout (45 s) and disconnects.
				return new EngineIOResponse('2');

			default:
				return new EngineIOResponse('6');   // EIO noop (fallback)
		}
	}

	/**
	 * Engine.IO v4 long-poll POST handler (socket.io 4.x transport).
	 *
	 * Accepts one or more EIO packets separated by 0x1e (Record Separator).
	 * Relevant packet types:
	 *   40   socket.io CONNECT (no-op here; session is lazily created on GET)
	 *   42   socket.io EVENT   (dispatched to command handlers)
	 *   41   socket.io DISCONNECT (ignored; idle handler cleans up)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	public function socketIOMessage(string $documentId): Response {
		$sid = $this->request->getParam('sid');
		if (!$sid) {
			return new EngineIOResponse('ok');
		}

		$body = (string)file_get_contents('php://input');

		// EIO v4 allows multiple packets in one POST, delimited by 0x1e.
		foreach (explode("\x1e", $body) as $packet) {
			if ($packet !== '') {
				$this->handleEngineIOPacket($packet, $sid, $documentId);
			}
		}

		return new EngineIOResponse('ok');
	}

	private function handleEngineIOPacket(string $packet, string $sid, string $documentId): void {
		// Packet must be at least "4X" (EIO MESSAGE + sio type).
		if (strlen($packet) < 2 || $packet[0] !== '4') {
			return;
		}

		$sioType = $packet[1];
		$payload = substr($packet, 2);

		if ($sioType === '2') {
			// socket.io EVENT: payload is JSON array ["eventName", ...args]
			$event = json_decode($payload, true);
			if (!is_array($event) || count($event) < 2 || $event[0] !== 'message') {
				return;
			}

			$command = $event[1];

			if (!is_array($command)) {
				$this->logger->debug('documentserver socketIO unrecognised command payload: {raw}', [
					'raw' => json_encode($command),
				]);
				return;
			}

			try {
				$this->logger->debug('documentserver socketIO command: type={type} keys={keys}', [
					'type' => $command['type'] ?? '?',
					'keys' => implode(',', array_keys($command)),
				]);
				$channel = $this->sessionFactory->getSession(
					$sid,
					$documentId,
					$this->getCommandDispatcher()
				);
				$channel->handleCommand($command);
			} catch (\Exception $e) {
				$this->logger->warning('documentserver socketIO command error: {error}', [
					'error' => $e->getMessage(),
					'exception' => $e,
				]);
			}
		}
		// sio types '0' (CONNECT) and '1' (DISCONNECT) require no action here.
	}
}
