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

use OCP\Files\Cache\IScanner;
use OCP\Files\Folder;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Provide local access to appdata folders
 */
class LocalAppData {
	private $appData;
	private $config;
	private $tmpManager;
	private LoggerInterface $logger;

	public function __construct(
		IAppData $appData,
		IConfig $config,
		ITempManager $tmpManager,
		LoggerInterface $logger
	) {
		$this->appData = $appData;
		$this->config = $config;
		$this->tmpManager = $tmpManager;
		$this->logger = $logger;
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
			// The callback (e.g. x2t producing Editor.bin) writes straight to the
			// on-disk appdata folder, bypassing the Nextcloud storage layer, so the
			// file cache never learns about the new files and a later getFile() would
			// throw NotFoundException. Rescan the folder so the writes become visible,
			// see #70 (editor stuck on "Loading document" because Editor.bin is on disk
			// but absent from oc_filecache). The temp-folder branch below does not need
			// this because copyFromLocal() writes back through File::putContent(),
			// which updates the cache itself. The scan must be RECURSIVE: x2t emits
			// not just Editor.bin but a media/ subfolder of embedded images, and
			// the open flow reads those back through the file API
			// (DocumentStore::getEmbeddedFiles -> media/.getDirectoryListing()). A
			// shallow scan would register Editor.bin but leave media/'s children
			// uncached, so embedded images would silently fail to load. A
			// concurrent writer can hold the scan lock; mirror NC core's
			// backgroundScan and skip rather than fail the open, the next access
			// rescans.
			try {
				$fullFolder->getStorage()->getScanner()->scan(
					$fullFolder->getInternalPath(),
					IScanner::SCAN_RECURSIVE
				);
			} catch (LockedException $e) {
				$this->logger->warning(
					'documentserver: appdata rescan after conversion skipped, folder locked',
					['exception' => $e, 'app' => 'documentserver_community']
				);
			}
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
