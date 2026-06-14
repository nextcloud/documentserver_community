<?php

declare(strict_types=1);
/**
 * Engine.IO v4 HTTP long-polling response.
 *
 * Packets are plain text strings:
 *   0{...}          EIO open (handshake)
 *   40              socket.io CONNECT ack (namespace /)
 *   42[...]         socket.io EVENT
 *   41              socket.io DISCONNECT
 *   6               EIO noop (heartbeat placeholder)
 *   ok              POST acknowledgement
 */

namespace OCA\DocumentServer;

use OC\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

class EngineIOResponse extends Response implements ICallbackResponse {
	private string $packet;

	public function __construct(string $packet, int $statusCode = Http::STATUS_OK) {
		$this->packet = $packet;
		$this->setStatus($statusCode);
		$this->addHeader('Content-Type', 'text/plain; charset=UTF-8');
		$this->addHeader('Content-Length', (string)strlen($packet));
	}

	public function callback(IOutput $output): void {
		if ($output->getHttpResponseCode() !== Http::STATUS_NOT_MODIFIED) {
			print $this->packet;
		}
	}
}
