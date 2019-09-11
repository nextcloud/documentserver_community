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

class RedisIPCBackend implements IIPCBackend {
	private $redis;

	public function __construct(\Redis $redis) {
		$this->redis = $redis;
	}

	public function initChannel(string $channel) {
	}

	public function pushMessage(string $channel, string $message) {
		$this->redis->rPush('ipc_' . $channel, $message);
	}

	public function popMessage(string $channel): ?string {
		$message = $this->redis->lPop('ipc_' . $channel);
		if ($message) {
			return $message;
		} else {
			return null;
		}
	}
}
