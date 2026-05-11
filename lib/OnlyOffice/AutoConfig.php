<?php
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

namespace OCA\DocumentServer\OnlyOffice;

use OCA\Onlyoffice\AppConfig;
use OCP\IURLGenerator;

class AutoConfig {
	private const SUPPORTED_DEFAULT_FORMATS = [
		'doc',
		'docx',
		'odp',
		'ods',
		'odt',
		'pdf',
		'ppt',
		'pptx',
		'xls',
		'xlsx',
	];

	private const SUPPORTED_EDIT_FORMATS = [
		'csv',
		'doc',
		'docx',
		'odp',
		'ods',
		'odt',
		'ppt',
		'pptx',
		'rtf',
		'txt',
		'xls',
		'xlsx',
	];

	private $urlGenerator;
	private $appConfig;

	public function __construct(IURLGenerator $urlGenerator, AppConfig $appConfig) {
		$this->urlGenerator = $urlGenerator;
		$this->appConfig = $appConfig;
	}

	public function autoConfigIfNeeded() {
		if ($this->shouldAutoConfig()) {
			$this->autoConfig();
		} elseif ($this->isCommunityDocumentServerConfigured()) {
			$this->syncSupportedFormats(false);
		}
	}

	/**
	 * Check if onlyoffice is not configured and we should fill our defaults
	 *
	 * @return bool
	 */
	private function shouldAutoConfig(): bool {
		return !$this->appConfig->GetDocumentServerUrl();
	}

	private function isCommunityDocumentServerConfigured(): bool {
		return strpos((string)$this->appConfig->GetDocumentServerUrl(), 'apps/documentserver_community') !== false;
	}

	/**
	 * Fill the documentserver url and other defaults
	 */
	private function autoConfig() {
		$url = substr($this->urlGenerator->linkToRouteAbsolute('documentserver_community.Static.webApps',
			['path' => '_']), 0, -strlen('/web-apps/_'));
		$this->appConfig->SetDocumentServerUrl($url);

		$this->syncSupportedFormats(true);
		$this->appConfig->SetSameTab(true);
	}

	private function syncSupportedFormats(bool $forceWrite): void {
		$formatSettings = $this->appConfig->FormatsSetting();
		$defaultFormats = [];
		$editFormats = [];
		$hasUnsupportedFormats = false;

		foreach ($formatSettings as $format => $settings) {
			if (!in_array($format, self::SUPPORTED_DEFAULT_FORMATS, true) && ($settings['def'] ?? false)) {
				$hasUnsupportedFormats = true;
			}
			if (!in_array($format, self::SUPPORTED_EDIT_FORMATS, true) && ($settings['edit'] ?? false)) {
				$hasUnsupportedFormats = true;
			}

			$defaultFormats[$format] = in_array($format, self::SUPPORTED_DEFAULT_FORMATS, true)
				&& ($settings['def'] ?? false);
			$editFormats[$format] = in_array($format, self::SUPPORTED_EDIT_FORMATS, true)
				&& ($settings['edit'] ?? false);
		}

		if (!$forceWrite && !$hasUnsupportedFormats) {
			return;
		}

		$this->appConfig->SetDefaultFormats($defaultFormats);
		$this->appConfig->SetEditableFormats($editFormats);
	}
}
