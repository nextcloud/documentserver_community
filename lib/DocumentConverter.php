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
use OCA\DocumentServer\Document\ConverterBinary;
use OCP\ITempManager;

class DocumentConverter {
	private $tempManager;

	public function __construct(ITempManager $tempManager) {
		$this->tempManager = $tempManager;
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
		$changesFolder = $sourceFolder . '/changes';
		mkdir($changesFolder);
		foreach ($changes as $key => $change) {
			file_put_contents($changesFolder . '/' . $key . '.json', '["' . $change->getChange() . '"]');
		}

		$xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>
<TaskQueueDataConvert xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:xsd=\"http://www.w3.org/2001/XMLSchema\">
	<m_sFileFrom>$sourceFolder/Editor.bin</m_sFileFrom>
	<m_sFileTo>$target</m_sFileTo>
	<m_bFromChanges>true</m_bFromChanges>
</TaskQueueDataConvert>
";

		$this->runXml($xml);

		\OC_Helper::rmdirr($changesFolder);
	}


	public function convertFiles(string $from, string $to, int $targetFormat = 8192) {
		$xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>
<TaskQueueDataConvert xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns:xsd=\"http://www.w3.org/2001/XMLSchema\">
	<m_sFileFrom>$from</m_sFileFrom>
	<m_sFileTo>$to</m_sFileTo>
	<m_nFormatTo>$targetFormat</m_nFormatTo>
</TaskQueueDataConvert>
";

		$this->runXml($xml);
	}

	private function runXml(string $xml) {
		$xmlFile = $this->tempManager->getTemporaryFile('.xml');
		file_put_contents($xmlFile, $xml);

		$converter = new ConverterBinary();
		$converter->run($xmlFile);
	}
}
