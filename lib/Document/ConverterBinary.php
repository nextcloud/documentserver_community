<?php

declare(strict_types=1);
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

use Psr\Log\LoggerInterface;

class ConverterBinary {
	public const BINARY_DIRECTORY = __DIR__ . '/../../3rdparty/onlyoffice/documentserver/server/FileConverter/bin';

	private $logger;

	public function __construct(LoggerInterface $logger) {
		$this->logger = $logger;
	}

	public function run(string $param, string $password = null): string {
		if (!is_executable(self::BINARY_DIRECTORY . '/x2t')) {
			@chmod(self::BINARY_DIRECTORY . '/x2t', 0755);
		}

		$descriptorSpec = [
			0 => ["pipe", "r"],// stdin
			1 => ["pipe", "w"],// stdout
			2 => ["pipe", "w"] // stderr
		];

		$pipes = [];
		$cmd = './x2t ' . escapeshellarg($param);
		if ($password) {
			$password = htmlspecialchars($password, ENT_XML1, 'UTF-8');
			$cmd .= ' ' . escapeshellarg("<TaskQueueDataConvert><m_sPassword>$password</m_sPassword></TaskQueueDataConvert>");
		}
		$process = proc_open($cmd, $descriptorSpec, $pipes, self::BINARY_DIRECTORY, ["LD_LIBRARY_PATH" => "."]);

		// proc_open returns false if x2t couldn't be spawned at all (missing
		// binary, fork failure). Without this the next lines fclose/read null
		// pipes and proc_close(false) warns, and the caller silently gets no
		// output instead of a clear failure.
		if ($process === false) {
			throw new DocumentConversionException("failed to start x2t");
		}

		@fclose($pipes[0]);
		$output = @stream_get_contents($pipes[1]);
		$error = @stream_get_contents($pipes[2]);

		$status = proc_close($process);

		if ($status == 90 || $status == 91) {
			throw new PasswordRequiredException($status);
		}

		if ($error) {
			throw new DocumentConversionException($error);
		}

		// x2t can fail with a nonzero exit status while writing nothing to stderr;
		// without this the caller treats a failed conversion as success and later
		// trips over the missing Editor.bin, see #70. Stderr is checked first so its
		// (more specific) message wins, including the "Empty sFileFrom or sFileTo"
		// string that test() relies on.
		if ($status !== 0) {
			throw new DocumentConversionException("x2t exited with status $status");
		}

		return $output;
	}

	public function test(): bool {
		try {
			$output = $this->run('');
			return strpos($output, 'OOX/binary file converter') !== false;
		} catch (\Exception $e) {
			if (trim((string)$e->getMessage()) === 'Empty sFileFrom or sFileTo') {
				return true;
			}
			$this->logger->error(
				'Error while testing x2t binary', 
				['exception' => $e, 'app' => 'documentserver_community']
			);
			return false;
		}
	}

	public function exists(): bool {
		return file_exists(self::BINARY_DIRECTORY . '/x2t');
	}
}
