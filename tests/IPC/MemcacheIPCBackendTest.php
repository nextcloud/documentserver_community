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

namespace OCA\DocumentServer\Tests\IPC;

use OCA\DocumentServer\IPC\IIPCBackend;
use OCA\DocumentServer\IPC\MemcacheIPCBackend;
use OC\Memcache\ArrayCache;

class MemcacheIPCBackendTest extends BackendTest {
	/** @var ArrayCache */
	private $cache;

	protected function setupBackend() {
		$this->cache = new ArrayCache();
	}

	protected function getBackend(): IIPCBackend {
		return new MemcacheIPCBackend($this->cache);
	}

	/**
	 * pushMessage() claims its slot with an atomic inc and writes the payload
	 * one statement later, so a pop can land in between. It must not consume
	 * the slot there: the message is on its way.
	 */
	public function testPopDuringInFlightPush() {
		$backend = $this->getBackend();

		// a writer that has claimed slot 1 but not yet written its payload
		$this->cache->inc("ch1::write_key");

		$this->assertEquals(null, $backend->popMessage("ch1", 1));

		// the writer finishes
		$this->cache->set("ch1::message_1", "in-flight", 100);

		$this->assertEquals("in-flight", $backend->popMessage("ch1", 1));
	}

	/**
	 * A message queued behind an in-flight one must not overtake it either.
	 */
	public function testInFlightPushKeepsOrder() {
		$backend = $this->getBackend();

		$this->cache->inc("ch1::write_key");
		$backend->pushMessage("ch1", "second");

		$this->assertEquals(null, $backend->popMessage("ch1", 1));

		$this->cache->set("ch1::message_1", "first", 100);

		$this->assertEquals("first", $backend->popMessage("ch1", 1));
		$this->assertEquals("second", $backend->popMessage("ch1", 1));
		$this->assertEquals(null, $backend->popMessage("ch1", 1));
	}

	/**
	 * Holding the pointer back must not turn into a wedged channel when the
	 * payload never arrives at all, e.g. a writer that died between claiming
	 * the slot and writing to it.
	 */
	public function testSlotThatNeverGetsItsPayloadIsGivenUpOn() {
		$backend = $this->getBackend();

		$this->cache->inc("ch1::write_key");
		$backend->pushMessage("ch1", "after the dead slot");

		$message = null;
		for ($i = 0; $i < 500 && $message === null; $i++) {
			$message = $backend->popMessage("ch1", 1);
		}

		$this->assertEquals("after the dead slot", $message);
	}
}
