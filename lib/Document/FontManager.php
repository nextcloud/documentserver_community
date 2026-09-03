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
		if (!is_executable(ConverterBinary::BINARY_DIRECTORY . '/../../tools/allfontsgen')) {
			@chmod(ConverterBinary::BINARY_DIRECTORY . '/../../tools/allfontsgen', 0755);
		}

		$this->localAppData->getReadLocalPath($this->getFontDir(), function (string $fontsDir) {
			// Absolute paths throughout: since 9.x allfontsgen no longer
			// resolves relative arguments against the working directory, and
			// with relative ones it just writes out an empty font list.
			$binDir = ConverterBinary::BINARY_DIRECTORY;
			$documentServer = $binDir . '/../../..';
			$cmd = $binDir . '/../../tools/allfontsgen \
				--input="' . $documentServer . '/core-fonts" \
				--input="' . $fontsDir . '" \
				--allfonts-web="' . $documentServer . '/sdkjs/common/AllFonts.js" \
				--allfonts="' . $binDir . '/AllFonts.js" \
				--images="' . $documentServer . '/sdkjs/common/Images" \
				--output-web="' . $documentServer . '/fonts" \
				--selection="' . $binDir . '/font_selection.bin"';

			$descriptorSpec = [
				0 => ["pipe", "r"],// stdin
				1 => ["pipe", "w"],// stdout
				2 => ["pipe", "w"] // stderr
			];

			$pipes = [];
			// Same environment x2t is given: since 9.x the converter's shared
			// libraries ship only in FileConverter/bin (the working directory
			// here), and allfontsgen cannot load them without this.
			$process = proc_open($cmd, $descriptorSpec, $pipes, ConverterBinary::BINARY_DIRECTORY, ["LD_LIBRARY_PATH" => "."]);

			if (!$process) {
				throw new \Exception("Failed to start allfontsgen");
			}

			fclose($pipes[0]);
			$output = stream_get_contents($pipes[1]);
			$error = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$status = proc_close($process);

			if ($error) {
				throw new \Exception($error);
			}

			$this->finishFontList($binDir . '/AllFonts.js', $status, $output);
		});
	}

	/**
	 * Check what allfontsgen produced, then pin the bundled font paths to
	 * x2t's working directory (server/FileConverter/bin).
	 *
	 * Two problems are handled here, both of which allfontsgen reports by
	 * exiting successfully with nothing on stderr. It writes no font list at
	 * all when it cannot create the file, and it writes an empty one when it
	 * cannot find the fonts. It also prefixes every path it does write with its
	 * own working directory, which stops resolving as soon as the app directory
	 * moves. In each case x2t ends up handing a null buffer to
	 * CFontFileLoader.LoadFontFromData, and any change replay that has to
	 * measure text - saving a spreadsheet, for one - fails with "Cannot read
	 * property 'length' of null". Paths to custom fonts live outside the app
	 * directory and stay absolute.
	 */
	private function finishFontList(string $allFontsJs, int $status, string $output): void {
		$content = @file_get_contents($allFontsJs);
		if ($content === false || strpos($content, '/core-fonts/') === false) {
			throw new \Exception(
				"allfontsgen produced no usable font list in $allFontsJs (exit status $status) "
				. trim($output)
			);
		}

		$pinned = preg_replace('|"[^"]*/core-fonts/|', '"../../../core-fonts/', $content);
		if ($pinned !== null && $pinned !== $content) {
			file_put_contents($allFontsJs, $pinned);
		}
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
