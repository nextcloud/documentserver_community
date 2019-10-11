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

use OC\RedisFactory;
use OCP\IConfig;

class RedisIPCFactory implements IIPCBackendFactory {
	private $redisFactory;
	private $config;

	public function __construct(RedisFactory $redisFactory, IConfig $config) {
		$this->redisFactory = $redisFactory;
		$this->config = $config;
	}

	public function isAvailable(): bool {
		$redisConfig = $this->config->getSystemValue('redis', false);
		$redisClusterConfig = $this->config->getSystemValue('redis.cluster', false);
		return $this->redisFactory->isAvailable() && (
				is_array($redisConfig) ||
				is_array($redisClusterConfig)
			);
	}

	public function getPriority(): int {
		return 10;
	}

	public function getInstance(): IIPCBackend {
		return new RedisIPCBackend($this->redisFactory->getInstance());
	}
}
