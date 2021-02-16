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

use OCP\ICacheFactory;
use OCP\IMemcache;

class MemcacheIPCFactory implements IIPCBackendFactory {
	private $memcacheFactory;

	public function __construct(ICacheFactory $memcacheFactory) {
		$this->memcacheFactory = $memcacheFactory;
	}

	public function isAvailable(): bool {
		return $this->memcacheFactory->isAvailable() && (
				$this->memcacheFactory->createDistributed() instanceof IMemcache
			);
	}

	public function getPriority(): int {
		return 20;
	}

	public function getInstance(): IIPCBackend {
		return new MemcacheIPCBackend($this->memcacheFactory->createDistributed('ipc'));
	}
}
