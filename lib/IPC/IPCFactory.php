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

class IPCFactory implements IIPCFactory {
	/** @var IIPCBackendFactory[] */
	private $backendFactories;

	public function registerBackend(IIPCBackendFactory $backendFactory) {
		$this->backendFactories[] = $backendFactory;
	}

	/**
	 * @return IIPCBackend
	 * @throws \Exception if no ipc backend is available
	 */
	private function getBackend() {
		/** @var IIPCBackendFactory[] $backends */
		$backends = array_filter($this->backendFactories, function(IIPCBackendFactory $backendFactory) {
			return $backendFactory->isAvailable();
		});
		usort($backends, function(IIPCBackendFactory $a, IIPCBackendFactory $b) {
			return $a->getPriority() - $b->getPriority();
		});

		if (count($backends)) {
			$backendFactory = $backends[0];
			return $backendFactory->getInstance();
		} else {
			throw new \Exception("No ipc backends available");
		}
	}

	/**
	 * @param string $name
	 * @return IIPCChannel
	 * @throws \Exception if no ipc backend is available
	 */
	public function getChannel(string $name): IIPCChannel {
		$backend = $this->getBackend();
		$backend->initChannel($name);
		return new IPCChannel($name, $backend);
	}

	/**
	 * Cleanup an ipc channel
	 *
	 * @param string $channel
	 */
	public function cleanupChannel(string $channel) {
		try {
			$backend = $this->getBackend();
			$backend->cleanupChannel($channel);
		} catch (\Exception $e) {
			return;
		}
	}
}
