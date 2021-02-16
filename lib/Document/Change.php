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

class Change {
	private $documentId;
	private $time;
	private $change;
	private $user;
	private $userOriginal;

	public function __construct(int $documentId, int $time, string $change, string $user, string $userOriginal) {
		$this->documentId = $documentId;
		$this->time = $time;
		$this->change = $change;
		$this->user = $user;
		$this->userOriginal = $userOriginal;
	}

	public function getDocumentId(): int {
		return $this->documentId;
	}

	public function getTime(): int {
		return $this->time;
	}

	public function getChange(): string {
		return $this->change;
	}

	public function getUser(): string {
		return $this->user;
	}

	public function getUserOriginal(): string {
		return $this->userOriginal;
	}

	public function getChangeIndex(): int {
		return $this->changeIndex;
	}

	public function formatForClient(): array {
		return [
			'docid' => (string)$this->getDocumentId(),
			'change' => '"' . $this->getChange() . '"',
			'time' => $this->getTime() * 1000,
			'user' => $this->getUser(),
			'useridoriginal' => $this->getUserOriginal(),
		];
	}

	public static function fromRow(array $row): self {
		return new Change(
			(int)$row['document_id'],
			(int)$row['time'],
			$row['change'],
			$row['user'],
			$row['user_original']
		);
	}
}
