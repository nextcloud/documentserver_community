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

namespace OCA\DocumentServer\XHRCommand;

use OCA\DocumentServer\Channel\Session;
use OCA\DocumentServer\Document\DocumentStore;
use OCA\DocumentServer\IPC\IIPCChannel;

/**
 * The chat backlog, which the editor asks for once when it opens a document.
 *
 * Answered even when empty: the panel resets its list from whatever arrives
 * first, so a late joiner sees the conversation instead of starting blank.
 */
class GetMessages implements ICommandHandler {
	private $documentStore;

	public function __construct(DocumentStore $documentStore) {
		$this->documentStore = $documentStore;
	}

	public function getType(): string {
		return 'getMessages';
	}

	public function handle(array $command, Session $session, IIPCChannel $sessionChannel, IIPCChannel $documentChannel, CommandDispatcher $commandDispatcher): void {
		$messages = $this->documentStore->getChatMessages($session->getDocumentId());
		if (!$messages) {
			return;
		}

		$sessionChannel->pushMessage(json_encode([
			'type' => 'message',
			'messages' => $messages,
		]));
	}
}
