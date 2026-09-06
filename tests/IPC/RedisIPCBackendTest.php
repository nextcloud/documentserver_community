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
use OCA\DocumentServer\IPC\RedisIPCBackend;

class RedisIPCBackendTest extends BackendTest {
	/** @var \Redis */
	private $redis;

	protected function setupBackend() {
		// Redis is optional: an instance without it configured runs the other
		// backends and skips this one, rather than failing on a dependency the
		// app itself treats as optional. (This used to call a method that does
		// not exist - getGetRedisFactory - so the test could only ever fatal;
		// it was never run anywhere.)
		$factory = \OCP\Server::get(\OC\RedisFactory::class);
		try {
			if (!$factory->isAvailable()) {
				$this->markTestSkipped('no redis configured');
			}
			$this->redis = $factory->getInstance();
		} catch (\Exception $e) {
			// the extension can be there with nothing listening
			$this->markTestSkipped('redis is not reachable: ' . $e->getMessage());
		}
	}

	protected function getBackend(): IIPCBackend {
		return new RedisIPCBackend($this->redis);
	}
}
