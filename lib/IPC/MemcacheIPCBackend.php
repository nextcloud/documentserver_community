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

namespace OCA\DocumentServer\IPC;

use OCP\IMemcache;

/**
 * IPC Channels built on top of memcache concurrency primitives
 */
class MemcacheIPCBackend implements IIPCBackend {
	private $memcache;

	public function __construct(IMemcache $memcache) {
		$this->memcache = $memcache;
	}

	public function initChannel(string $channel) {
		$this->memcache->add("$channel::write_key", 0);
		$this->memcache->add("$channel::read_key", 0);
	}

	public function pushMessage(string $channel, string $message) {
		$key = $this->memcache->inc("$channel::write_key");
		$this->memcache->set("$channel::message_$key", $message);
	}

	public function popMessage(string $channel): ?string {
		$writeKey = $this->memcache->get("$channel::write_key");
		$readKey = $this->memcache->inc("$channel::read_key");

		if ($writeKey >= $readKey) {
			$message = $this->memcache->get("$channel::message_$readKey");
			$this->memcache->remove("$channel::message_$readKey");
			return $message;
		} else {
			// no unread message, return read pointer

			$this->memcache->dec("$channel::read_key");

			return null;
		}
	}
}
