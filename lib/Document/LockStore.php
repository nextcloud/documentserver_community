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


use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class LockStore {
	private $connection;
	private $timeFactory;

	public function __construct(
		IDBConnection $connection,
		ITimeFactory $timeFactory
	) {
		$this->connection = $connection;
		$this->timeFactory = $timeFactory;
	}

	public function storeLock(int $document, string $user, $block) {
		$query = $this->connection->getQueryBuilder();
		$query->insert("documentserver_locks")
			->values([
				"document_id" => $query->createNamedParameter($document, IQueryBuilder::PARAM_INT),
				"user" => $query->createNamedParameter($user),
				"time" => $query->createNamedParameter($this->timeFactory->getTime(), IQueryBuilder::PARAM_INT),
				"block" => $query->createNamedParameter(json_encode($block)),
			]);
		$query->execute();
	}

	/**
	 * @param int $document
	 * @return Lock[]
	 */
	public function getLocksForDocument(int $document): array {
		$query = $this->connection->getQueryBuilder();
		$query->select("lock_id", "document_id", "user", "time", "block")
			->from("documentserver_locks")
			->where($query->expr()->eq("document_id", $query->createNamedParameter($document, IQueryBuilder::PARAM_INT)));
		$rows = $query->execute()->fetchAll();

		return array_map([Lock::class, "fromRow"], $rows);
	}

	/**
	 * @param int $document
	 * @param string $user
	 * @return Lock[]
	 */
	public function releaseLocks(int $document, string $user): array {
		$query = $this->connection->getQueryBuilder();
		$query->select("lock_id", "document_id", "user", "time", "block")
			->from("documentserver_locks")
			->where($query->expr()->eq("document_id", $query->createNamedParameter($document, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->eq("user", $query->createNamedParameter($user)));
		$rows = $query->execute()->fetchAll();

		$released = array_map([Lock::class, "fromRow"], $rows);

		$lockIds = array_map(function(Lock $lock) {
			return $lock->getLockId();
		}, $released);

		$query = $this->connection->getQueryBuilder();
		$query->delete("documentserver_locks")
			->where($query->expr()->in("lock_id", $query->createNamedParameter($lockIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$query->execute();

		return $released;
	}
}
