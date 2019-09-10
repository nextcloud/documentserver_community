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

interface IIPCBackendFactory {
	/**
	 * Whether or not this backend is available on the current instance
	 *
	 * @return bool
	 */
	public function isAvailable(): bool;

	/**
	 * Get the priority of this backend, lower meaning higher priority
	 *
	 * Backend priority is used to select the highest performing backend that is
	 * available in an instance.
	 *
	 * Some guidelines for priority values:
	 *
	 * 1: high performance and scalability, no reason not to use if available
	 * 10: decent performance, should work fine on most instances
	 * 100: fallback backends, not great but work pretty much anywhere
	 *
	 * @return int
	 */
	public function getPriority(): int;

	/**
	 * Create a new backend instance
	 *
	 * @return IIPCBackend
	 */
	public function getInstance(): IIPCBackend;
}
