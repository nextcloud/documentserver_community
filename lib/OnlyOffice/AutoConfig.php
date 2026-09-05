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
	/**
	 * Formats OnlyOffice becomes the default opener for on a fresh install.
	 *
	 * A product choice, not a capability list: the bundled server can open far
	 * more than this, but taking over .txt, .csv or .html from Nextcloud's own
	 * handlers is not what an admin expects from installing a document server.
	 * Anything here that the bundled server does not know is dropped.
	 */
	private const DEFAULT_OPEN_FORMATS = [
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

	private const FORMATS_FILE = __DIR__ . '/../../3rdparty/onlyoffice/documentserver/document-formats/onlyoffice-docs-formats.json';

	private $urlGenerator;
	private $appConfig;

	public function __construct(IURLGenerator $urlGenerator, AppConfig $appConfig) {
		$this->urlGenerator = $urlGenerator;
		$this->appConfig = $appConfig;
	}

	public function autoConfigIfNeeded() {
		if ($this->shouldAutoConfig()) {
			$this->autoConfig();
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

	/**
	 * Fill the documentserver url and other defaults
	 */
	private function autoConfig() {
		$url = substr($this->urlGenerator->linkToRouteAbsolute('documentserver_community.Static.webApps',
			['path' => '_']), 0, -strlen('/web-apps/_'));
		$this->appConfig->SetDocumentServerUrl($url);

		$this->seedSupportedFormats();
		$this->appConfig->SetSameTab(true);
	}

	/**
	 * The format matrix the bundled document server ships, keyed by extension.
	 *
	 * The connector carries its own copy of the same file, but the two can be
	 * different versions, and it is this one that says what our converter can
	 * actually do.
	 *
	 * @return array<string, array>
	 */
	private function bundledFormats(): array {
		$json = @file_get_contents(self::FORMATS_FILE);
		if ($json === false) {
			return [];
		}
		$formats = json_decode($json, true);
		if (!is_array($formats)) {
			return [];
		}

		$byName = [];
		foreach ($formats as $format) {
			if (isset($format['name'])) {
				$byName[$format['name']] = $format;
			}
		}
		return $byName;
	}

	/**
	 * Write the format defaults, once, when the connector is not configured yet.
	 *
	 * Only a seed: from here on the admin owns these two settings. This used to
	 * run on every request against a hardcoded list of formats, which silently
	 * reverted anything the admin enabled in the settings UI that the list did
	 * not mention - PDF editing among them, which the bundled server has a
	 * dedicated editor for.
	 *
	 * The connector leaves lossy-editable formats (odt, ods, odp, csv, rtf,
	 * txt) off by default, so seeding them is the point of doing this at all.
	 */
	private function seedSupportedFormats(): void {
		$bundled = $this->bundledFormats();
		if (!$bundled) {
			return;
		}

		$defaultFormats = [];
		$editFormats = [];

		foreach ($bundled as $name => $format) {
			$actions = $format['actions'] ?? [];
			$editFormats[$name] = is_array($actions)
				&& (in_array('edit', $actions, true) || in_array('lossy-edit', $actions, true));
			$defaultFormats[$name] = in_array($name, self::DEFAULT_OPEN_FORMATS, true);
		}

		$this->appConfig->SetDefaultFormats($defaultFormats);
		$this->appConfig->SetEditableFormats($editFormats);
	}
}
