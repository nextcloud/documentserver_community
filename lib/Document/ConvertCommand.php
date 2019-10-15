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

use Sabre\Xml\Writer;
use Sabre\Xml\XmlSerializable;

class ConvertCommand implements XmlSerializable {
	/** @var bool|null */
	private $isPDFA = null;
	/** @var bool|null */
	private $fromChanges = null;
	/** @var bool|null */
	private $isNoBase64 = null;
	/** @var int|null */
	private $formatTo = null;
	/** @var string */
	private $fileFrom;
	/** @var string */
	private $fileTo;
	/** @var string|null */
	private $fontDir = null;
	/** @var string|null */
	private $themeDir = null;
	/** @var bool|null */
	private $noBase64 = null;
	/** @var \DateTime */
	private $timeStamp;

	public function __construct(string $fileFrom, string $fileTo) {
		$this->fileFrom = $fileFrom;
		$this->fileTo = $fileTo;
		$this->timeStamp = new \DateTime();
	}

	public function setIsPDFA(bool $isPDFA): void {
		$this->isPDFA = $isPDFA;
	}

	public function setFromChanges(bool $fromChanges): void {
		$this->fromChanges = $fromChanges;
	}

	public function setIsNoBase64(bool $isNoBase64): void {
		$this->isNoBase64 = $isNoBase64;
	}

	public function setTargetFormat(int $formatTo): void {
		$this->formatTo = $formatTo;
	}

	public function setFontDir(string $fontDir): void {
		$this->fontDir = $fontDir;
	}

	public function setThemeDir(string $themeDir): void {
		$this->themeDir = $themeDir;
	}

	public function setNoBase64(bool $noBase64): void {
		$this->noBase64 = $noBase64;
	}

	public function setTimeStamp(\DateTime $timeStamp): void {
		$this->timeStamp = $timeStamp;
	}

	public function setFileFrom(string $fileFrom): void {
		$this->fileFrom = $fileFrom;
	}

	public function setFileTo(string $fileTo): void {
		$this->fileTo = $fileTo;
	}

	function xmlSerialize(Writer $writer) {
		$xsiNS = "{http://www.w3.org/2001/XMLSchema-instance}";
		$keyMap = [
			"m_sFileFrom" => "fileFrom",
			"m_sFileTo" => "fileTo",
			"m_nFormatTo" => "formatTo",
			"m_bIsPDFA" => "isPDFA",
			"m_bFromChanges" => "fromChanges",
			"m_sFontDir" => "fontDir",
			"m_sThemeDir" => "themeDir",
			"m_bIsNoBase64" => "noBase64",
			"m_oTimestamp" => "timeStamp",
		];

		foreach ($keyMap as $key => $var) {
			$value = $this->$var;
			if ($value instanceof \DateTime) {
				$value = $value->format(DATE_ATOM);
			}
			if (is_bool($value)) {
				$value = $value ? "true" : "false";
			}
			if ($value === null) {
				$writer->write([
					"name" => $key,
					"attributes" => [
						$xsiNS . "nil" => "true",
					],
				]);
			} else {
				$writer->write([
					"name" => $key,
					"value" => $value,
				]);
			}
		}
	}

	public function serialize(): string {
		$xmlWriter = new Writer();
		$xmlWriter->namespaceMap["http://www.w3.org/2001/XMLSchema-instance"] = "xsi";
		$xmlWriter->namespaceMap["http://www.w3.org/2001/XMLSchema"] = "xsd";
		$xmlWriter->openMemory();
		$xmlWriter->writeElement("TaskQueueDataConvert", $this);
		return $xmlWriter->outputMemory();
	}
}
