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

namespace OCA\DocumentServer\Tests\Channel;

use OCA\DocumentServer\Channel\SessionManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

/**
 * @group DB
 */
class SessionManagerTest extends TestCase {
	/** @var IDBConnection */
	private $connection;
	/** @var ITimeFactory|MockObject */
	private $timeFactory;
	/** @var SessionManager */
	private $manager;

	private $time = 1;

	protected function setUp(): void {
		parent::setUp();

		$this->connection = \OC::$server->getDatabaseConnection();
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')
			->willReturnCallback(function() {
				return $this->time;
			});

		$this->manager = new SessionManager($this->connection, $this->timeFactory);
	}

	public function testNewGet() {
		$this->time = 10;

		$this->assertNull($this->manager->getSession('foo'));

		$this->manager->newSession('foo', 5);

		$session = $this->manager->getSession('foo');
		$this->assertNotNull($session);

		$this->assertEquals('foo', $session->getSessionId());
		$this->assertEquals(5, $session->getDocumentId());
		$this->assertEquals('', $session->getUser());
		$this->assertEquals('', $session->getUserOriginal());
		$this->assertEquals(10, $session->getLastSeen());

		$this->manager->authenticate($session, 'user', 'original', false);
		$session = $this->manager->getSession('foo');

		$this->assertEquals('foo', $session->getSessionId());
		$this->assertEquals(5, $session->getDocumentId());
		$this->assertEquals('user', $session->getUser());
		$this->assertEquals('original', $session->getUserOriginal());
		$this->assertEquals(10, $session->getLastSeen());
	}

	protected function tearDown(): void {
		$query = $this->connection->getQueryBuilder();
		$query->delete('documentserver_sess')->execute();

		parent::tearDown();
	}

	public function testLastSeen() {
		$this->time = 10;

		$this->manager->markAsSeen('foo');

		$this->time = 11;

		$this->manager->newSession('foo', 5);

		$session = $this->manager->getSession('foo');

		$this->assertEquals(11, $session->getLastSeen());

		$this->time = 12;

		$this->manager->markAsSeen('foo');

		$session = $this->manager->getSession('foo');

		$this->assertEquals(12, $session->getLastSeen());
	}

	public function testCleanSessions() {
		$this->time = 10;
		$this->manager->newSession('foo', 5);

		$this->time = 50;
		$this->manager->newSession('bar', 5);

		$this->assertNotNull($this->manager->getSession('foo'));
		$this->assertNotNull($this->manager->getSession('bar'));

		$this->time = 130;
		$this->manager->cleanSessions();

		$this->assertNull($this->manager->getSession('foo'));
		$this->assertNotNull($this->manager->getSession('bar'));
	}

	public function testIsDocumentActive() {
		$this->time = 10;

		$this->assertFalse($this->manager->isDocumentActive(5));
		$this->assertFalse($this->manager->isDocumentActive(6));

		$this->manager->newSession('foo', 5);

		$this->assertTrue($this->manager->isDocumentActive(5));
		$this->assertFalse($this->manager->isDocumentActive(6));
	}

	public function testGetSessionCount() {
		$this->time = 10;

		$this->assertEquals(0, $this->manager->getSessionCount());

		$this->manager->newSession('foo', 5);

		$this->assertEquals(1, $this->manager->getSessionCount());
	}
}
