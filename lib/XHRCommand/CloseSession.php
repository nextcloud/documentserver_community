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
 * sdkjs stepping out of co-authoring, from DocsCoApi.disconnect().
 *
 * This is not the editor being closed, and must not be treated as one. sdkjs
 * sends it whenever it drops the editor to view mode while the page stays
 * open: a licence verdict on the number of users in the document - which is
 * evaluated as soon as there is a second participant - rights taken away,
 * disconnectEveryone, a critical error, a document reopened under a new url.
 * Ending the session here and writing the document out took the document
 * folder away from a page that was still editing.
 *
 * So it only stops the session being a participant, and a client that turns
 * out to still be polling revives itself. Closing the editor - destroyEditor()
 * taking the iframe out of the DOM - and closing the tab say nothing at all
 * through this channel; they are covered by the unload beacon
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
		$this->sessionCloser->sessionStoppedCoAuthoring($session);
	}
}
