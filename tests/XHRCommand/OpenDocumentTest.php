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

namespace OCA\DocumentServer\Tests\XHRCommand;

use OCA\DocumentServer\Channel\Session;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\IPC\IIPCChannel;
use OCA\DocumentServer\OnlyOffice\URLDecoder;
use OCA\DocumentServer\XHRCommand\OpenDocument;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\ISession;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Importing an image is a request the server makes on the client's say-so: the
 * url is whatever the editor put in the command. Nextcloud's HTTP client is
 * what keeps that from reaching the server's own network - it refuses private
 * addresses and re-checks them on redirect - but it hands anything that is not
 * http to curl, so the scheme is ours to check.
 */
class OpenDocumentTest extends TestCase {
	/** @var IClientService|MockObject */
	private $clientService;
	/** @var DocumentStore|MockObject */
	private $documentStore;
	/** @var OpenDocument */
	private $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->clientService = $this->createMock(IClientService::class);
		$this->documentStore = $this->createMock(DocumentStore::class);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example.com/image');

		$this->handler = new OpenDocument(
			$urlGenerator,
			$this->documentStore,
			$this->createMock(URLDecoder::class),
			$this->createMock(ISession::class),
			$this->clientService,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function session(): Session {
		return new Session('sid', 5, 'user', 'user', 'user', 100, false, 1);
	}

	/**
	 * @return array{IIPCChannel|MockObject, array} the channel, and the reply
	 *                                              pushed to it
	 */
	private function importImages(array $urls): array {
		$reply = [];
		$channel = $this->createMock(IIPCChannel::class);
		$channel->method('pushMessage')->willReturnCallback(function (string $message) use (&$reply) {
			$reply = json_decode($message, true);
		});

		$this->handler->imageUrls(
			['data' => $urls],
			$this->session(),
			$channel,
			$this->createMock(IIPCChannel::class)
		);

		return [$channel, $reply];
	}

	public static function unsupportedSchemes(): array {
		return [
			['file:///etc/passwd'],
			['file://localhost/etc/passwd'],
			['gopher://example.com/'],
			['ftp://example.com/image.png'],
			['/etc/passwd'],
		];
	}

	#[DataProvider('unsupportedSchemes')]
	public function testAnImageIsNotFetchedOverSomethingOtherThanHttp(string $url) {
		// the guard is before the client: it is never asked for one
		$this->clientService->expects($this->never())->method('newClient');
		$this->documentStore->expects($this->never())->method('saveDocumentFile');

		[, $reply] = $this->importImages([$url]);

		$this->assertEquals(-104, $reply['data']['data']['error']);
		$this->assertEquals('error', $reply['data']['data']['urls'][0]['url']);
	}

	public function testAnHttpImageIsFetchedThroughNextcloudsClient() {
		$body = fopen('php://memory', 'r+');
		fwrite($body, 'an image');
		rewind($body);

		$response = $this->createMock(\OCP\Http\Client\IResponse::class);
		$response->method('getBody')->willReturn($body);

		$client = $this->createMock(IClient::class);
		$client->expects($this->once())
			->method('get')
			->with('https://example.com/image.png', ['stream' => true])
			->willReturn($response);
		$this->clientService->method('newClient')->willReturn($client);

		$this->documentStore->expects($this->once())->method('saveDocumentFile');

		[, $reply] = $this->importImages(['https://example.com/image.png']);

		$this->assertEquals(0, $reply['data']['data']['error']);
		$this->assertEquals('https://cloud.example.com/image', $reply['data']['data']['urls'][0]['url']);
	}

	public function testAFailedFetchIsReportedRatherThanThrown() {
		$client = $this->createMock(IClient::class);
		$client->method('get')->willThrowException(new \Exception('no'));
		$this->clientService->method('newClient')->willReturn($client);

		$this->documentStore->expects($this->never())->method('saveDocumentFile');

		[, $reply] = $this->importImages(['https://example.com/image.png']);

		$this->assertEquals(-104, $reply['data']['data']['error']);
	}
}
