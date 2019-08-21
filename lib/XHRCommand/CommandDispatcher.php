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

namespace OCA\DocumentServer\XHRCommand;

use OCA\DocumentServer\Channel\Session;
use OCP\IPC\IIPCChannel;

class CommandDispatcher {
	const HANDLERS = [
		AuthCommand::class,
	];

	/** @var ICommandHandler[] */
	private $handlers = [];

	public function addHandler(ICommandHandler $handler) {
		$this->handlers[] = $handler;
	}

	public function handle(array $command, Session $session, IIPCChannel $channel): void {
		$type = $command['type'];
		foreach ($this->handlers as $handler) {
			if ($handler->getType() === $type) {
				$handler->handle($command, $session, $channel, $this);
				return;
			}
		}
	}
}
