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

namespace OCA\DocumentServer\Document;

use OCP\Files\File;
use OCA\DocumentServer\DocumentConverter;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;

class DocumentStore {
	private $appData;
	private $documentConverter;
	private $config;
	private $rootFolder;

	public function __construct(
		IAppData $appData,
		DocumentConverter $documentConverter,
		IConfig $config,
		IRootFolder $rootFolder
	) {
		$this->appData = $appData;
		$this->documentConverter = $documentConverter;
		$this->config = $config;
		$this->rootFolder = $rootFolder;
	}

	/**
	 * Hackish way to get local path for appdata folder
	 *
	 * @param ISimpleFolder $folder
	 * @return string
	 */
	private function getLocalPath(ISimpleFolder $folder): string {
		$class = new \ReflectionClass($folder);
		$prop = $class->getProperty('folder');
		$prop->setAccessible(true);
		/** @var Folder $fullFolder */
		$fullFolder = $prop->getValue($folder);
		$path = $fullFolder->getPath();
		return $this->config->getSystemValueString('datadirectory') . $path;
	}

	public function getDocumentPath(int $documentId): string {
		return $this->getLocalPath($this->getDocumentFolder($documentId));
	}

	private function getDocumentFolder(int $documentId): ISimpleFolder {
		try {
			return $this->appData->getFolder("doc_$documentId");
		} catch (NotFoundException $e) {
			return $this->appData->newFolder("doc_$documentId");
		}
	}

	public function getDocumentForEditor(int $documentId, File $sourceFile, string $sourceFormat): ISimpleFile {
		$docFolder = $this->getDocumentFolder($documentId);
		try {
			return $docFolder->getFile('Editor.bin');
		} catch (NotFoundException $e) {
			$source = $sourceFile->fopen('r');

			if (!$source) {
				throw new NotFoundException();
			}

			$localPath = $this->getLocalPath($docFolder);
			$this->documentConverter->getEditorBinary($source, $sourceFormat, $localPath);

			// maybe save in a new db table
			$docFolder->newFile('fileid')->putContent((string)$sourceFile->getId());
			$docFolder->newFile('owner')->putContent((string)$sourceFile->getOwner()->getUID());

			return $docFolder->getFile('Editor.bin');
		}
	}

	public function saveChanges(int $documentId, array $changes) {
		$docFolder = $this->getDocumentFolder($documentId);

		$owner = $docFolder->getFile('owner')->getContent();
		$sourceFileId = (int)$docFolder->getFile('fileid')->getContent();
		$sourceFiles = $this->rootFolder->getUserFolder($owner)->getById($sourceFileId);
		if (count($sourceFiles)) {
			/** @var File $sourceFile */
			$sourceFile = current($sourceFiles);
		} else {
			throw new NotFoundException('Source file not found');
		}

		$targetExtension = $sourceFile->getExtension();

		$localPath = $this->getLocalPath($docFolder);

		$target = $localPath . '/saved.' . $targetExtension;
		$this->documentConverter->saveChanges($localPath, $changes, $target);
		$savedContent = fopen($target, 'r');

		$sourceFile->putContent(stream_get_contents($savedContent));
	}

	/**
	 * @return int[]
	 * @throws NotFoundException
	 */
	public function getOpenDocuments(): array {
		$content = $this->appData->getDirectoryListing();
		$content = array_filter($content, function (ISimpleFolder $folder) {
			return substr($folder->getName(), 0, 4) === 'doc_';
		});

		return array_map(function (ISimpleFolder $folder) {
			return (int)substr($folder->getName(), 4);
		}, $content);
	}
}
