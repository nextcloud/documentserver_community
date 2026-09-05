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

use OCA\DocumentServer\DB\QueryHelper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DBException;
use OCP\IDBConnection;

class ChangeStore {
	/**
	 * How often a batch of changes is re-tried when another editor took the
	 * change indexes it was aiming for. Every attempt after the first works
	 * from a max that already includes the other writer's changes, so a
	 * handful covers far more concurrent savers than a document ever has;
	 * the bound is only there so a genuinely broken insert cannot loop.
	 */
	private const MAX_INSERT_ATTEMPTS = 10;

	/**
	 * Upper bound, in microseconds, of the wait before a retry. Writers that
	 * collided are in lockstep by definition, so retrying them all immediately
	 * just reproduces the collision; the wait is randomised to break that up
	 * and grows with the attempt count.
	 */
	private const RETRY_BACKOFF_USEC = 5000;

	private $connection;
	private $timeFactory;

	public function __construct(
		IDBConnection $connection,
		ITimeFactory $timeFactory
	) {
		$this->connection = $connection;
		$this->timeFactory = $timeFactory;
	}

	/**
	 * Two editors saving at the same moment used to read the same max change
	 * index and aim for the same slots. (document_id, change_index) is unique,
	 * so the loser did not corrupt the change log - it got a constraint
	 * violation out of an unclosed transaction, which surfaced as a failed
	 * save. Read the max inside the transaction and retry the batch when it
	 * collides: by then the other writer has committed, so the fresh max is
	 * past its changes.
	 *
	 * @throws DBException
	 */
	public function addChangesForDocument(int $documentId, array $changes, string $user, string $userOriginal) {
		$time = $this->timeFactory->getTime();

		for ($attempt = 1;; $attempt++) {
			$this->connection->beginTransaction();

			try {
				$changeIndex = $this->getMaxChangeIndexForDocument($documentId) + 1;

				foreach ($changes as $change) {
					$this->addChangeForDocument($documentId, $change, $user, $userOriginal, $time, $changeIndex);
					$changeIndex++;
				}

				$this->connection->commit();
				return;
			} catch (\Throwable $e) {
				if ($this->connection->inTransaction()) {
					$this->connection->rollBack();
				}

				$isCollision = $e instanceof DBException
					&& $e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION;
				if (!$isCollision || $attempt >= self::MAX_INSERT_ATTEMPTS) {
					throw $e;
				}

				usleep(random_int(0, self::RETRY_BACKOFF_USEC * $attempt));
			}
		}
	}

	private function addChangeForDocument(int $documentId, string $change, string $user, string $userOriginal, int $time, int $changeIndex) {
		$query = $this->connection->getQueryBuilder();

		$query->insert('documentserver_changes')
			->values([
				'document_id' => $query->createNamedParameter($documentId, \PDO::PARAM_INT),
				'change' => $query->createNamedParameter($change),
				'change_index' => $query->createNamedParameter($changeIndex, \PDO::PARAM_INT),
				'time' => $query->createNamedParameter($time, \PDO::PARAM_INT),
				'user' => $query->createNamedParameter($user),
				'user_original' => $query->createNamedParameter($userOriginal),
			]);
		QueryHelper::executeStatement($query);
	}

	public function getMaxChangeIndexForDocument(int $documentId): int {
		$query = $this->connection->getQueryBuilder();

		$query->select($query->func()->max('change_index'))
			->from('documentserver_changes')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)));
		$index = QueryHelper::fetchOne($query);

		return ($index === false || $index === null) ? -1 : (int)$index;
	}

	public function getChangesForDocument(int $documentId): array {
		$query = $this->connection->getQueryBuilder();

		$query->select('change', 'time', 'document_id', 'user', 'user_original', 'change_index')
			->from('documentserver_changes')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)))
			->orderBy('change_id', 'ASC');
		$rows = QueryHelper::fetchAll($query);
		return array_map([Change::class, 'fromRow'], $rows);
	}

	public function getChangesAndMarkProcessingForDocument(int $documentId): array {
		$query = $this->connection->getQueryBuilder();

		$query->update('documentserver_changes')
			->set('processing', $query->createNamedParameter(true, \PDO::PARAM_BOOL))
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)));
		QueryHelper::executeStatement($query);

		$query = $this->connection->getQueryBuilder();

		$query->select('change', 'time', 'document_id', 'user', 'user_original', 'change_index')
			->from('documentserver_changes')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)))
			->andWhere($query->expr()->eq('processing', $query->createNamedParameter(true, \PDO::PARAM_INT)))
			->orderBy('change_id', 'ASC');
		$rows = QueryHelper::fetchAll($query);
		return array_map([Change::class, 'fromRow'], $rows);
	}

	public function unmarkProcessing(int $documentId) {
		$query = $this->connection->getQueryBuilder();

		$query->update('documentserver_changes')
			->set('processing', $query->createNamedParameter(false, \PDO::PARAM_BOOL))
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)));
		QueryHelper::executeStatement($query);
	}

	public function deleteProcessedChanges(int $documentId) {
		$query = $this->connection->getQueryBuilder();

		$query->delete('documentserver_changes')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)))
			->andWhere($query->expr()->eq('processing', $query->createNamedParameter(true, \PDO::PARAM_INT)));
		QueryHelper::executeStatement($query);
	}

	public function deleteChangesByIndex(int $documentId, int $changeIndex) {
		$query = $this->connection->getQueryBuilder();

		$query->delete('documentserver_changes')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)))
			->andWhere($query->expr()->gte('change_index', $query->createNamedParameter($changeIndex, \PDO::PARAM_INT)));
		QueryHelper::executeStatement($query);
	}
}
