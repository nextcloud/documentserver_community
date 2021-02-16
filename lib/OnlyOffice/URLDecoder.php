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

namespace OCA\DocumentServer\OnlyOffice;

use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCA\Onlyoffice\Crypt;
use OCP\Files\Folder;
use OCP\IUserSession;
use OCP\Share\IManager;
use function Sabre\HTTP\decodePathSegment;

class URLDecoder {
	private $crypt;
	private $userSession;
	private $shareManager;
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

	public function getFileForUrl(string $url): ?File {
		$url = decodePathSegment($url);
		$query = [];
		parse_str(parse_url($url, PHP_URL_QUERY), $query);
		if (!isset($query['doc'])) {
			return null;
		}
		return $this->getFileForToken($query['doc']);
	}


	public function getFileForToken(string $token): ?File {
		[$hashData, $error] = $this->crypt->ReadHash($token);

		if ($error) {
			return null;
		}

		$fileId = $hashData->fileId;

		if (isset($hashData->shareToken)) {
			$share = $this->shareManager->getShareByToken($hashData->shareToken);

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
			if ($this->userSession->isLoggedIn()) {
				$userId = $this->userSession->getUser()->getUID();
			} elseif (isset($hashData->ownerId)) {
				$userId = $hashData->ownerId;
			} elseif (isset($hashData->userId)) {
				$userId = $hashData->userId;
			} else {
				throw new \Exception("Can't get owner id from document url");
			}

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
