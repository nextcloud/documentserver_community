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

namespace OCA\DocumentServer\IPC;

use OCA\DocumentServer\Channel\Channel;
use OCP\IMemcache;

/**
 * IPC Channels built on top of memcache concurrency primitives
 */
class MemcacheIPCBackend implements IIPCBackend {
	/**
	 * How many consecutive pops may find a claimed slot still empty before the
	 * slot is written off. A push claims its slot and writes the payload one
	 * statement later, so a real message lands within a poll or two; the bound
	 * only exists so that a writer which died in between cannot block the
	 * channel for good. Channel::getResponse() polls every 100ms.
	 */
	private const MAX_PUBLISH_MISSES = 50;

	private $memcache;

	public function __construct(IMemcache $memcache) {
		$this->memcache = $memcache;
	}

	public function initChannel(string $channel) {
		$this->memcache->add("$channel::write_key", 0);
		$this->memcache->add("$channel::read_key", 0);
	}

	public function cleanupChannel(string $channel) {
		$this->memcache->remove("$channel::write_key");
		$this->memcache->remove("$channel::read_key");
	}

	/**
	 * Claiming the slot with an atomic inc is what stops two concurrent pushes
	 * from writing the same key, so the payload can only be written afterwards.
	 * That leaves a window in which the slot exists but the message does not,
	 * which popMessage() has to cope with.
	 */
	public function pushMessage(string $channel, string $message) {
		$key = $this->memcache->inc("$channel::write_key");
		$this->memcache->set("$channel::message_$key", $message, Channel::TIMEOUT * 4);
	}

	public function popMessage(string $channel, int $timeout): ?string {
		$writeKey = (int)$this->memcache->get("$channel::write_key");
		$readKey = (int)$this->memcache->get("$channel::read_key") + 1;

		if ($writeKey < $readKey) {
			// no unread message
			return null;
		}

		// The read pointer only moves once the message is in hand. Claiming the
		// slot first - as incrementing read_key up front did - consumed the slot
		// of a push that had not written its payload yet, and that message was
		// then gone for good.
		$message = $this->memcache->get("$channel::message_$readKey");

		if ($message === null || !$this->memcache->cad("$channel::message_$readKey", $message)) {
			// Either the push has not written its payload yet, or another reader
			// on this channel took the message between the two calls. Both are
			// resolved by leaving the pointer alone and letting the next poll
			// have another go. A slot that stays empty for longer than any push
			// can take is written off, so a writer that died mid-publish does
			// not stall the channel.
			$misses = $this->memcache->inc("$channel::miss_$readKey");
			if (is_int($misses) && $misses < self::MAX_PUBLISH_MISSES) {
				return null;
			}

			$this->memcache->remove("$channel::miss_$readKey");
			$this->memcache->inc("$channel::read_key");

			return null;
		}

		$this->memcache->remove("$channel::miss_$readKey");
		$this->memcache->inc("$channel::read_key");

		return $message;
	}
}
