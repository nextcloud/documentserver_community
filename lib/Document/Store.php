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

namespace OCA\Documents\Document;

use OCA\Documents\DocumentConverter;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IDBConnection;
use function Sabre\HTTP\decodePathSegment;

class Store {
	/** @var IAppData */
	private $appData;
	/** @var IDBConnection */
	private $connection;
	/** @var DocumentConverter */
	private $documentConverter;

	public function __construct(IAppData $appData, IDBConnection $connection, DocumentConverter $documentConverter) {
		$this->appData = $appData;
		$this->connection = $connection;
		$this->documentConverter = $documentConverter;
	}

	private function getDocumentRoot(): ISimpleFolder {
		try {
			return $this->appData->getFolder('documents');
		} catch (NotFoundException $e) {
			return $this->appData->newFolder('documents');
		}
	}

	private function getDocumentFolder(int $documentId): ISimpleFolder {
		$root = $this->getDocumentRoot();
		try {
			return $root->getFolder("doc_$documentId");
		} catch (NotFoundException $e) {
			return $root->newFolder("doc_$documentId");
		}
	}

	public function getDocumentForEditor(int $documentId, string $documentUrl, string $sourceFormat): ISimpleFile {
		$docFolder = $this->getDocumentFolder($documentId);
		try {
			return $docFolder->getFile('Editor.bin');
		} catch (NotFoundException $e) {
			$source = fopen($documentUrl, 'r');

			if (!$source) {
				throw new NotFoundException();
			}

			$file = $docFolder->newFile('Editor.bin');
			$file->putContent($this->documentConverter->convert($source, $sourceFormat, 'bin'));

			return $file;
		}
	}

	public function getChangesForDocument(int $documentId): array {

	}
}
