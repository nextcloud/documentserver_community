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
use OCA\DocumentServer\Document\SaveHandler;
use OCA\DocumentServer\IPC\IIPCChannel;
use Psr\Log\LoggerInterface;

/**
 * Write the document out now, on request from the editor.
 *
 * This is what the onlyoffice connector's "Keep intermediate versions when
 * editing" setting turns into: it sets editorConfig.customization.forcesave,
 * which gives the editor a Save button, and pressing it sends forceSaveStart.
 * Without a handler the command was dropped and the button did nothing, so the
 * setting looked broken from the settings UI.
 *
 * The reply comes in two parts, and both have to carry the same timestamp: the
 * client stores the one from forceSaveStart and ignores a forceSave whose time
 * does not match it.
 */
class ForceSave implements ICommandHandler {
	/** sdkjs c_oAscForceSaveTypes.Button */
	private const TYPE_BUTTON = 1;
	/** sdkjs c_oAscServerCommandErrors.NoError */
	private const ERROR_NONE = 0;
	/** sdkjs c_oAscServerCommandErrors.UnknownError */
	private const ERROR_UNKNOWN = 3;

	private $saveHandler;
	private $logger;

	public function __construct(SaveHandler $saveHandler, LoggerInterface $logger) {
		$this->saveHandler = $saveHandler;
		$this->logger = $logger;
	}

	public function getType(): string {
		return 'forceSaveStart';
	}

	public function handle(array $command, Session $session, IIPCChannel $sessionChannel, IIPCChannel $documentChannel, CommandDispatcher $commandDispatcher): void {
		$documentId = $session->getDocumentId();
		$time = time() * 1000;

		try {
			$this->saveHandler->flushChanges($documentId);
			$success = true;
		} catch (\Exception $e) {
			$this->logger->warning('documentserver force save failed for document {doc}: {error}', [
				'doc' => $documentId,
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			$success = false;
		}

		// Reported after the fact rather than before: the save is synchronous
		// here, and telling the editor a save started only to fail it a moment
		// later leaves its button stuck in the saving state.
		$sessionChannel->pushMessage(json_encode([
			'type' => 'forceSaveStart',
			'messages' => [
				'code' => $success ? self::ERROR_NONE : self::ERROR_UNKNOWN,
				'time' => $time,
			],
		]));

		if (!$success) {
			return;
		}

		$sessionChannel->pushMessage(json_encode([
			'type' => 'forceSave',
			'messages' => [
				'type' => self::TYPE_BUTTON,
				'time' => $time,
				'success' => true,
			],
		]));
	}
}
