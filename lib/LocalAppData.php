<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Robin Appelman <robin@icewind.nl>
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

namespace OCA\DocumentServer;

use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\ITempManager;

/**
 * Provide local access to appdata folders
 */
class LocalAppData {
	private $appData;
	private $config;
	private $tmpManager;

	public function __construct(
		IAppData $appData,
		IConfig $config,
		ITempManager $tmpManager
	) {
		$this->appData = $appData;
		$this->config = $config;
		$this->tmpManager = $tmpManager;
	}

	/**
	 * Hackish way to get the full Folder from SimpleFolder
	 *
	 * @param ISimpleFolder $folder
	 * @return string
	 */
	public function upgradeFolder(ISimpleFolder $folder): Folder {
		$class = new \ReflectionClass($folder);
		$prop = $class->getProperty('folder');
		$prop->setAccessible(true);
		/** @var Folder $fullFolder */
		return $prop->getValue($folder);
	}

	/**
	 * Get the local path for a SimpleFolder,
	 *
	 * the path is returned by callback instead of as return value to ensure cleanup and writeback can happen
	 *
	 * @param ISimpleFolder $folder
	 * @param callable $callback function(string $localPath): void
	 */
	public function getReadLocalPath(ISimpleFolder $folder, callable $callback) {
		$fullFolder = $this->upgradeFolder($folder);
		$path = $fullFolder->getPath();
		$localPath = $this->config->getSystemValueString('datadirectory') . $path;

		if (is_dir($localPath)) {
			$callback($localPath);
		} else {
			$localPath = $this->tmpManager->getTemporaryFolder();
			$this->copyToLocal($fullFolder, $localPath);
			$callback($localPath);
		}
	}

	/**
	 * Get the local path for a SimpleFolder,
	 *
	 * the path is returned by callback instead of as return value to ensure cleanup and writeback can happen
	 *
	 * @param ISimpleFolder $folder
	 * @param callable $callback function(string $localPath): void
	 */
	public function getReadWriteLocalPath(ISimpleFolder $folder, callable $callback) {
		$fullFolder = $this->upgradeFolder($folder);
		$path = $fullFolder->getPath();
		$localPath = $this->config->getSystemValueString('datadirectory') . $path;

		if (is_dir($localPath)) {
			$callback($localPath);
		} else {
			$localPath = $this->tmpManager->getTemporaryFolder();
			$this->copyToLocal($fullFolder, $localPath);
			$callback($localPath);
			$this->copyFromLocal($localPath, $fullFolder);
		}
	}

	private function copyToLocal(Folder $folder, string $localPath) {
		foreach ($folder->getDirectoryListing() as $node) {
			$localNodePath = $localPath . '/' . $node->getName();
			if ($node instanceof Folder) {
				@mkdir($localNodePath);
				$this->copyToLocal($node, $localNodePath);
			} elseif ($node instanceof File) {
				file_put_contents($localNodePath, $node->fopen('r'));
			}
		}
	}

	private function copyFromLocal(string $localPath, Folder $folder) {
		foreach ((new \DirectoryIterator($localPath)) as $node) {
			if ($node->isDot()) {
				continue;
			}

			$localNodePath = $node->getPathname();
			if ($node->isDir()) {
				try {
					$subFolder = $folder->get($node->getFilename());
				} catch (NotFoundException $e) {
					$subFolder = $folder->newFolder($node->getFilename());
				}
				$this->copyFromLocal($localNodePath, $subFolder);
			} else {
				try {
					$file = $folder->get($node->getFilename());
				} catch (NotFoundException $e) {
					$file = $folder->newFile($node->getFilename());
				}
				$file->putContent(fopen($localNodePath, 'r'));
			}
		}
	}
}
