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

use OCA\Documents\Document\Store;
use OCA\DocumentServer\Channel\Session;
use OCA\DocumentServer\Document\ChangeStore;
use OCP\IPC\IIPCChannel;

class SaveChangesCommand implements ICommandHandler {
	private $changeStore;

	public function __construct(ChangeStore $changeStore) {
		$this->changeStore = $changeStore;
	}

	public function getType(): string {
		return 'saveChanges';
	}

	public function handle(array $command, Session $session, IIPCChannel $channel, CommandDispatcher $commandDispatcher): void {
		$changes = json_decode($command['changes']);

		foreach ($changes as $change) {
			$this->changeStore->addChangeForDocument($session->getDocumentId(), $change, $session->getUser(), $session->getUserOriginal());
		}

		$now = time() * 1000;
		$channel->pushMessage('{"type":"unSaveLock","index":0,"time":' . $now . '}');
	}
}
