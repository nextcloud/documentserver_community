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

use OCA\DocumentServer\Document\ConverterBinary;

class SetupCheck {
	private $converterBinary;

	public function __construct(ConverterBinary $converterBinary) {
		$this->converterBinary = $converterBinary;
	}

	public function check(): bool {
		return $this->converterBinary->test();
	}

	public function getHint(): string {
		$x2t = ConverterBinary::BINARY_DIRECTORY . '/x2t';
		if (!is_callable('proc_open')) {
			return "'proc_open' needs to be enabled";
		} elseif (!is_callable('proc_close')) {
			return "'proc_close' needs to be enabled";
		} elseif (!file_exists($x2t)) {
			return "x2t binary missing, please try removing and re-installing the app";
		} elseif (PHP_INT_SIZE === 4) {
			return "32 bit setups are not supported";
		} elseif (PHP_OS_FAMILY !== "Linux") {
			return "only linux based servers are supported";
		}

		@chmod($x2t, 0755);
		if (!is_executable(ConverterBinary::BINARY_DIRECTORY . '/x2t')) {
			return "can't execute x2t binary, ensure php can execute binaries in the app folder";
		}
		$ldError = $this->lddError();
		if (strpos($ldError, 'ld-linux') !== false) {
			return "using a musl libc based distribution is not supported";
		} elseif (strpos($ldError, 'version `GLIBC_') !== false) {
			return "glibc version 2.17 or higher is required";
		} elseif ($ldError) {
			return "one or more dependencies are missing or outdated";
		}

		return '';
	}

	private function lddError(): string {
		$descriptorSpec = [
			0 => ["pipe", "r"],// stdin
			1 => ["pipe", "w"],// stdout
			2 => ["pipe", "w"] // stderr
		];

		$pipes = [];
		proc_open('ldd x2t', $descriptorSpec, $pipes, ConverterBinary::BINARY_DIRECTORY, []);

		@fclose($pipes[0]);
		$error = @stream_get_contents($pipes[2]);

		return trim($error);
	}
}
