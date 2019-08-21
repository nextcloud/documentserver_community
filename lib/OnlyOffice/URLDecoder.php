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

namespace OCA\DocumentServer\OnlyOffice;


use Behat\Testwork\Suite\Exception\ParameterNotFoundException;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCA\Onlyoffice\Crypt;
use OCP\Files\Folder;
use OCP\IUserSession;
use OCP\Share\IManager;

class URLDecoder {
	/** @var Crypt */
	private $crypt;

	/** @var IUserSession */
	private $userSession;

	/** @var IManager */
	private $shareManager;

	/** @var IRootFolder */
	private $rootFolder;

	public function __construct(
		Crypt $crypt,
		IUserSession $userSession,
		IManager $shareManager,
		IRootFolder $rootFolder
	) {
		$this->crypt = $crypt;
		$this->userSession = $userSession;
		$this->shareManager = $shareManager;
		$this->rootFolder = $rootFolder;
	}


	public function getFileForToken(string $token): ?File {
		list ($hashData, $error) = $this->crypt->ReadHash($token);

		if ($error) {
			return null;
		}

		$fileId = $hashData->fileId;

		if ($this->userSession->isLoggedIn()) {
			$userId = $this->userSession->getUser()->getUID();
		} else {
			$userId = $hashData->userId;
		}

		if(isset($hashData->token)) {
			$share = $this->shareManager->getShareByToken($hashData->token);

			$node = $share->getNode();

			if ($node instanceof Folder) {
				$files = $node->getById($fileId);
				if (count($files)) {
					return current($files);
				} else {
					return null;
				}
			} else {
				return $node;
			}
		} else {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$files = $userFolder->getById($fileId);
			if (count($files)) {
				return current($files);
			} else {
				return null;
			}
		}
	}
}
