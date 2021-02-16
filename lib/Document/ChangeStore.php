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

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

class ChangeStore {
	private $connection;
	private $timeFactory;

	public function __construct(
		IDBConnection $connection,
		ITimeFactory $timeFactory
	) {
		$this->connection = $connection;
		$this->timeFactory = $timeFactory;
	}

	public function addChangesForDocument(int $documentId, array $changes, string $user, string $userOriginal) {
		$time = $this->timeFactory->getTime();
		$changeIndex = $this->getMaxChangeIndexForDocument($documentId) + 1;

		$this->connection->beginTransaction();

		foreach ($changes as $change) {
			$this->addChangeForDocument($documentId, $change, $user, $userOriginal, $time, $changeIndex);
			$changeIndex++;
		}

		$this->connection->commit();
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
		$query->execute();
	}

	public function getMaxChangeIndexForDocument(int $documentId): int {
		$query = $this->connection->getQueryBuilder();

		$query->select($query->func()->max('change_index'))
			->from('documentserver_changes')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)));
		$index = $query->execute()->fetchColumn();

		return ($index === false) ? -1 : (int)$index;
	}

	public function getChangesForDocument(int $documentId): array {
		$query = $this->connection->getQueryBuilder();

		$query->select('change', 'time', 'document_id', 'user', 'user_original', 'change_index')
			->from('documentserver_changes')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)))
			->orderBy('change_id', 'ASC');
		$rows = $query->execute()->fetchAll();
		return array_map([Change::class, 'fromRow'], $rows);
	}

	public function getChangesAndMarkProcessingForDocument(int $documentId): array {
		$query = $this->connection->getQueryBuilder();

		$query->update('documentserver_changes')
			->set('processing', $query->createNamedParameter(true, \PDO::PARAM_BOOL))
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)));
		$query->execute();

		$query = $this->connection->getQueryBuilder();

		$query->select('change', 'time', 'document_id', 'user', 'user_original', 'change_index')
			->from('documentserver_changes')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)))
			->andWhere($query->expr()->eq('processing', $query->createNamedParameter(true, \PDO::PARAM_INT)))
			->orderBy('change_id', 'ASC');
		$rows = $query->execute()->fetchAll();
		return array_map([Change::class, 'fromRow'], $rows);
	}

	public function unmarkProcessing(int $documentId) {
		$query = $this->connection->getQueryBuilder();

		$query->update('documentserver_changes')
			->set('processing', $query->createNamedParameter(false, \PDO::PARAM_BOOL))
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)));
		$query->execute();
	}

	public function deleteProcessedChanges(int $documentId) {
		$query = $this->connection->getQueryBuilder();

		$query->delete('documentserver_changes')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)))
			->andWhere($query->expr()->eq('processing', $query->createNamedParameter(true, \PDO::PARAM_INT)));
		$query->execute();
	}

	public function deleteChangesByIndex(int $documentId, int $changeIndex) {
		$query = $this->connection->getQueryBuilder();

		$query->delete('documentserver_changes')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)))
			->andWhere($query->expr()->gte('change_index', $query->createNamedParameter($changeIndex, \PDO::PARAM_INT)));
		$query->execute();
	}
}
