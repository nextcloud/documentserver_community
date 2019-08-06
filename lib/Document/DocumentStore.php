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

use OCA\DocumentServer\DocumentConverter;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\IDBConnection;

class DocumentStore {
	private $appData;
	private $connection;
	private $documentConverter;
	private $config;

	public function __construct(IAppData $appData, IDBConnection $connection, DocumentConverter $documentConverter, IConfig $config) {
		$this->appData = $appData;
		$this->connection = $connection;
		$this->documentConverter = $documentConverter;
		$this->config = $config;
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

	private function getDocumentFolder(int $documentId): ISimpleFolder {
		try {
			return $this->appData->getFolder("doc_$documentId");
		} catch (NotFoundException $e) {
			return $this->appData->newFolder("doc_$documentId");
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

			$localPath = $this->getLocalPath($docFolder);
			$this->documentConverter->getEditorBinary($source, $sourceFormat, $localPath);

			return $docFolder->getFile('Editor.bin');
		}
	}

	public function addChangeForDocument(int $documentId, string $change, string $user, string $userOriginal) {
		$query = $this->connection->getQueryBuilder();

		$query->insert('documentserver_changes')
			->values([
				'document_id' => $query->createNamedParameter($documentId, \PDO::PARAM_INT),
				'change' => $query->createNamedParameter($change),
				'time' => time(),
				'user' => $query->createNamedParameter($user),
				'user_original' => $query->createNamedParameter($userOriginal),
			]);
		$query->execute();
	}

	public function getChangesForDocument(int $documentId): array {
		$query = $this->connection->getQueryBuilder();

		$query->select('change', 'time', 'document_id', 'user', 'user_original')
			->from('documentserver_changes')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)));
		$rows = $query->execute()->fetchAll();
		return array_map([Change::class, 'fromRow'], $rows);
	}
}
