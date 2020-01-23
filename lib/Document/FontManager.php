<?php declare(strict_types=1);
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

namespace OCA\DocumentServer\Document;

use OCA\DocumentServer\LocalAppData;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

class FontManager {
	private $appData;
	private $localAppData;

	public function __construct(
		IAppData $appData,
		LocalAppData $localAppData
	) {
		$this->appData = $appData;
		$this->localAppData = $localAppData;
	}

	public function rebuildFonts() {
		$this->localAppData->getReadLocalPath($this->getFontDir(), function (string $fontsDir) {
			$cmd = '../../tools/allfontsgen \
				--input="../../../core-fonts" \
				--input="' . $fontsDir . '" \
				--allfonts-web="../../../sdkjs/common/AllFonts.js" \
				--allfonts="AllFonts.js" \
				--images="../../../sdkjs/common/Images" \
				--output-web="../../../fonts" \
				--selection="font_selection.bin"';

			$descriptorSpec = [
				0 => ["pipe", "r"],// stdin
				1 => ["pipe", "w"],// stdout
				2 => ["pipe", "w"] // stderr
			];

			$pipes = [];
			proc_open($cmd, $descriptorSpec, $pipes, ConverterBinary::BINARY_DIRECTORY, []);

			fclose($pipes[0]);
			$error = stream_get_contents($pipes[2]);

			if ($error) {
				throw new \Exception($error);
			}
		});
	}

	private function getFontDir(): ISimpleFolder {
		try {
			return $this->appData->getFolder('fonts');
		} catch (NotFoundException $e) {
			return $this->appData->newFolder('fonts');
		}
	}

	/**
	 * @return string[]
	 */
	public function listFonts(): array {
		$dir = $this->getFontDir();
		$fonts = $dir->getDirectoryListing();
		return array_map(function (ISimpleFile $file) {
			return $file->getName();
		}, $fonts);
	}

	public function addFont(string $path) {
		if (!file_exists($path)) {
			throw new \Exception("Font not found: $path");
		}
		if (substr($path, -4) !== '.ttf') {
			throw new \Exception("Only ttf fonts are accepted");
		}

		$dir = $this->getFontDir();
		$fontFile = $dir->newFile(basename($path));
		$fontData = file_get_contents($path);
		$fontFile->putContent($fontData);
	}

	public function removeFont(string $name) {
		$dir = $this->getFontDir();
		try {
			$dir->getFile($name)->delete();
		} catch (\Exception $e) {
			throw new \Exception("Font not added: $name");
		}
	}
}
