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

namespace OCA\DocumentServer\Channel;

class Session {
	private $documentId;
	private $sessionId;
	private $user;
	private $userOriginal;
	private $lastSeen;

	public function __construct(string $sessionId, int $documentId, string $user, string $userOriginal, int $lastSeen) {
		$this->documentId = $documentId;
		$this->sessionId = $sessionId;
		$this->user = $user;
		$this->userOriginal = $userOriginal;
		$this->lastSeen = $lastSeen;
	}

	public function getDocumentId(): int {
		return $this->documentId;
	}

	public function getSessionId(): string {
		return $this->sessionId;
	}

	public function getUser(): string {
		return $this->user;
	}

	public function getUserOriginal(): string {
		return $this->userOriginal;
	}

	public function getLastSeen(): int {
		return $this->lastSeen;
	}

	public static function fromRow(array $row): self {
		return new Session(
			$row['session_id'],
			(int)$row['document_id'],
			$row['user'],
			$row['user_original'],
			(int)$row['last_seen']
		);
	}
}
