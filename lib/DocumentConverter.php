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

namespace OCA\DocumentServer;

use OCA\DocumentServer\Document\Change;
use OCA\DocumentServer\Document\ConvertCommand;
use OCA\DocumentServer\Document\ConverterBinary;
use OCP\ITempManager;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sabre\Xml\Writer;
use SplFileInfo;

class DocumentConverter {
	private $tempManager;
	private $converter;

	public function __construct(ITempManager $tempManager, ConverterBinary $converterBinary) {
		$this->tempManager = $tempManager;
		$this->converter = $converterBinary;
	}

	public function getEditorBinary($source, string $sourceExtension, string $targetFolder) {
		$sourceFile = $this->tempManager->getTemporaryFile(".$sourceExtension");
		file_put_contents($sourceFile, $source);

		$this->convertFiles($sourceFile, "$targetFolder/Editor.bin");
	}

	/**
	 * @param string $sourceFolder
	 * @param Change[] $changes
	 * @param string $target
	 */
	public function saveChanges(string $sourceFolder, array $changes, string $target) {
		try {
			$changesFolder = $sourceFolder . '/changes';
			mkdir($changesFolder);
			foreach ($changes as $key => $change) {
				file_put_contents($changesFolder . '/' . $key . '.json', '["' . $change->getChange() . '"]');
			}

			$command = new ConvertCommand("$sourceFolder/Editor.bin", $target);
			$command->setFromChanges(true);

			$this->runCommand($command);
		} finally {
			self::rmdirr($changesFolder);
		}
	}

	private function rmdirr($path) {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($files as $fileInfo) {
			/** @var SplFileInfo $fileInfo */
			if ($fileInfo->isLink()) {
				unlink($fileInfo->getPathname());
			} else if ($fileInfo->isDir()) {
				rmdir($fileInfo->getRealPath());
			} else {
				unlink($fileInfo->getRealPath());
			}
		}
		rmdir($path);
	}


	public function convertFiles(string $from, string $to, int $targetFormat = 8192) {
		$command = new ConvertCommand($from, $to);
		$command->setTargetFormat($targetFormat);

		$this->runCommand($command);
	}

	public function runCommand(ConvertCommand $command) {
		$xmlFile = $this->tempManager->getTemporaryFile('.xml');
		$xmlWriter = new Writer();
		$xmlWriter->namespaceMap["http://www.w3.org/2001/XMLSchema-instance"] = "xsi";
		$xmlWriter->namespaceMap["http://www.w3.org/2001/XMLSchema"] = "xsd";
		$xmlWriter->openUri($xmlFile);
		$xmlWriter->writeElement("TaskQueueDataConvert", $command);
		$xmlWriter->flush();

		$this->converter->run($xmlFile);
	}
}
