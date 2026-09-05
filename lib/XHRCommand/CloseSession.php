<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2026 Fabrice Meyer <meyer.fabrice@gmx.fr>
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
use OCA\DocumentServer\Channel\SessionCloser;
use OCA\DocumentServer\IPC\IIPCChannel;

/**
 * sdkjs stepping out of the document, from DocsCoApi.disconnect(): the editor
 * dropping to view mode, a document reopened under a new url, rights taken
 * away. The session is done sending changes either way, so it is treated as a
 * departure - see SessionCloser.
 *
 * The editor being closed does not come through here. DocsAPI's destroyEditor()
 * takes the iframe out of the DOM, which gives the code inside it no chance to
 * say anything; that path is covered by the unload beacon
 * (js/close-beacon.js).
 */
class CloseSession implements ICommandHandler {
	private $sessionCloser;

	public function __construct(SessionCloser $sessionCloser) {
		$this->sessionCloser = $sessionCloser;
	}

	public function getType(): string {
		return 'close';
	}

	public function handle(array $command, Session $session, IIPCChannel $sessionChannel, IIPCChannel $documentChannel, CommandDispatcher $commandDispatcher): void {
		$this->sessionCloser->sessionLeft($session);
	}
}
