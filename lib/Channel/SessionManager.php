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


use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

class SessionManager {
	private $connection;
	private $timeFactory;

	public function __construct(IDBConnection $connection, ITimeFactory $timeFactory) {
		$this->connection = $connection;
		$this->timeFactory = $timeFactory;
	}

	public function getSession(string $sessionId): ?Session {
		$query = $this->connection->getQueryBuilder();

		$query->select('session_id', 'document_id', 'user', 'user_original', 'last_seen')
			->from('documentserver_sessions')
			->where($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)));

		$row = $query->execute()->fetch();
		if ($row) {
			return Session::fromRow($row);
		} else {
			return null;
		}
	}

	public function newSession(string $sessionId, int $documentId, string $user, string $userOriginal) {
		$query = $this->connection->getQueryBuilder();

		$query->insert('documentserver_sessions')
			->values([
				'session_id' => $query->createNamedParameter($sessionId),
				'document_id' => $query->createNamedParameter($documentId, \PDO::PARAM_INT),
				'last_seen' => $query->createNamedParameter($this->timeFactory->getTime(), \PDO::PARAM_INT),
				'user' => $query->createNamedParameter($user),
				'user_original' => $query->createNamedParameter($userOriginal),
			]);
		$query->execute();
	}

	public function markAsSeen(string $sessionId) {
		$query = $this->connection->getQueryBuilder();

		$query->update('documentserver_sessions')
			->set('last_seen', $query->createNamedParameter($this->timeFactory->getTime(), \PDO::PARAM_INT))
			->where($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)));
		$query->execute();
	}

	public function cleanSessions() {
		$query = $this->connection->getQueryBuilder();

		$cutoffTime = $this->timeFactory->getTime() - (Channel::TIMEOUT * 4);

		$query->delete('documentserver_sessions')
			->where($query->expr()->lt('last_seen', $query->createNamedParameter($cutoffTime, \PDO::PARAM_INT)));
		$query->execute();
	}

	public function isDocumentActive(int $documentId): bool {
		$query = $this->connection->getQueryBuilder();

		$query->select('session_id')
			->from('documentserver_sessions')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)));

		return (bool)$query->execute()->fetchColumn();
	}
}
