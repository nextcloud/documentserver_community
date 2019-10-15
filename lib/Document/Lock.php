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


class Lock implements \JsonSerializable {
	private $lockId;
	private $documentId;
	private $user;
	private $time;
	private $block;

	public function __construct(int $lockId, int $documentId, string $user, \DateTime $time, $block) {
		$this->lockId = $lockId;
		$this->documentId = $documentId;
		$this->user = $user;
		$this->time = $time;
		$this->block = $block;
	}

	public function getLockId(): int {
		return $this->lockId;
	}

	public function getDocumentId(): int {
		return $this->documentId;
	}

	public function getUser(): string {
		return $this->user;
	}

	public function getTime(): \DateTime {
		return $this->time;
	}

	public function getBlock() {
		return $this->block;
	}

	public function jsonSerialize() {
		return [
			"time" => $this->getTime()->getTimestamp() * 1000,
			"user" => $this->getUser(),
			"block" => $this->getBlock(),
		];
	}

	public static function fromRow(array $row): Lock {
		return new Lock(
			(int)$row['lock_id'],
			(int)$row['document_id'],
			$row['user'],
			new \DateTime('@' . $row['time']),
			json_decode($row['block'], true)
		);
	}
}
