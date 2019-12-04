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

namespace OCA\DocumentServer\Tests\IPC;

use OCA\DocumentServer\IPC\IIPCBackend;
use Test\TestCase;

abstract class BackendTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$this->setupBackend();

		$this->getBackend()->initChannel("ch1");
		$this->getBackend()->initChannel("ch2");
	}

	abstract protected function setupBackend();

	abstract protected function getBackend(): IIPCBackend;

	public function testPushPop() {
		$backend1 = $this->getBackend();
		$backend2 = $this->getBackend();

		$backend1->pushMessage("ch1", "foo");
		$this->assertEquals("foo", $backend2->popMessage("ch1", 1));
	}

	public function testPopEmpty() {
		$backend = $this->getBackend();

		$this->assertEquals(null, $backend->popMessage("ch1", 1));
	}

	public function testPushPopAfterEmpty() {
		$backend1 = $this->getBackend();
		$backend2 = $this->getBackend();

		$this->assertEquals(null, $backend2->popMessage("ch1", 1));

		$backend1->pushMessage("ch1", "foo");
		$this->assertEquals("foo", $backend2->popMessage("ch1", 1));
	}

	public function testPushPopMultiple() {
		$backend1 = $this->getBackend();
		$backend2 = $this->getBackend();

		$backend1->pushMessage("ch1", "foo");
		$backend1->pushMessage("ch1", "bar");
		$backend1->pushMessage("ch1", "asd");
		$this->assertEquals("foo", $backend2->popMessage("ch1", 1));
		$this->assertEquals("bar", $backend2->popMessage("ch1", 1));
		$this->assertEquals("asd", $backend2->popMessage("ch1", 1));
		$this->assertEquals(null, $backend2->popMessage("ch1", 1));
	}

	public function testPushPopSeparateChannels() {
		$backend1 = $this->getBackend();
		$backend2 = $this->getBackend();

		$backend1->pushMessage("ch1", "foo");
		$backend1->pushMessage("ch2", "bar");
		$this->assertEquals("foo", $backend2->popMessage("ch1", 1));
		$this->assertEquals(null, $backend2->popMessage("ch1", 1));
		$this->assertEquals("bar", $backend2->popMessage("ch2", 1));
		$this->assertEquals(null, $backend2->popMessage("ch2", 1));
	}
}
