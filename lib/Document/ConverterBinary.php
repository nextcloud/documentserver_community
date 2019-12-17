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

use OCP\ILogger;

class ConverterBinary {
	const BINARY_DIRECTORY = __DIR__ . '/../../3rdparty/onlyoffice/documentserver/server/FileConverter/bin';

	private $logger;

	public function __construct(ILogger $logger) {
		$this->logger = $logger;
	}

	public function run(string $param): string {
		$descriptorSpec = [
			0 => ["pipe", "r"],// stdin
			1 => ["pipe", "w"],// stdout
			2 => ["pipe", "w"] // stderr
		];

		$pipes = [];
		$process = proc_open('./x2t ' . escapeshellarg($param), $descriptorSpec, $pipes, self::BINARY_DIRECTORY, []);

		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);

		proc_close($process);

		if ($error) {
			throw new DocumentConversionException($error);
		} else {
			return $output;
		}
	}

	public function test(): bool {
		try {
			$output = $this->run('');
			return strpos($output, 'OOX/binary file converter') !== false;
		} catch (\Exception $e) {
			if (trim($e->getMessage()) === 'Empty sFileFrom or sFileTo') {
				return true;
			}
			$this->logger->logException($e, [
				'app' => 'documentserver_community',
				'Message' => 'Error while testing x2t binary',
			]);
			return false;
		}
	}

	public function exists(): bool {
		return file_exists(self::BINARY_DIRECTORY . '/x2t');
	}
}
