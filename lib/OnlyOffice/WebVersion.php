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

namespace OCA\DocumentServer\OnlyOffice;

use OCP\ICacheFactory;

class WebVersion {
	private $cache;

	public function __construct(ICacheFactory $cacheFactory) {
		$this->cache = $cacheFactory->createLocal('documentserver_');
	}

	public function getWebUIVersion(): string {
		$cached = $this->cache->get('webversion');
		if ($cached) {
			return $cached;
		}

		$path = __DIR__ . '/../../3rdparty/onlyoffice/documentserver/web-apps/apps/api/documents/api.js';
		$apiJS = file_get_contents($path);
		if ($apiJS && preg_match("|DocsAPI\.DocEditor\.version\s*=\s*function\(\) *\{\n\s+return\s'(\d+.\d+.\d+)';\n\s+}|", $apiJS, $matches)) {
			$version = $matches[1];
			$this->cache->set('webversion', $version);
			return $version;
		} else {
			return '0.0.0';
		}
	}
}
