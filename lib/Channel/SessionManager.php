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

	public function getSessionCount(): int {
		$query = $this->connection->getQueryBuilder();

		$query->select($query->func()->count())
			->from('documentserver_sess');
		return (int)$query->execute()->fetchColumn();
	}

	public function getSession(string $sessionId): ?Session {
		$query = $this->connection->getQueryBuilder();

		$query->select('session_id', 'document_id', 'user', 'user_original', 'last_seen', 'readonly', 'user_index')
			->from('documentserver_sess')
			->where($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)));

		$row = $query->execute()->fetch();
		if ($row) {
			return Session::fromRow($row);
		} else {
			return null;
		}
	}

	private function getNextUserIndex(int $documentId): int {
		$query = $this->connection->getQueryBuilder();

		$query->select($query->createFunction('MAX(' . $query->getColumnName('user_index') . ')'))
			->from('documentserver_sess')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)));

		return $query->execute()->fetchColumn() + 1;
	}

	public function newSession(string $sessionId, int $documentId) {
		$userId = $this->getNextUserIndex($documentId);

		$query = $this->connection->getQueryBuilder();
		$now = $this->timeFactory->getTime();

		$query->insert('documentserver_sess')
			->values([
				'session_id' => $query->createNamedParameter($sessionId),
				'document_id' => $query->createNamedParameter($documentId, \PDO::PARAM_INT),
				'last_seen' => $query->createNamedParameter($now, \PDO::PARAM_INT),
				'user' => $query->createNamedParameter(""),
				'user_original' => $query->createNamedParameter(""),
				'readonly' => $query->createNamedParameter(1, \PDO::PARAM_INT),
				'user_index' => $query->createNamedParameter($userId, \PDO::PARAM_INT),
			]);
		$query->execute();
	}

	public function authenticate(Session $session, string $user, string $userOriginal, bool $readOnly): Session {
		$query = $this->connection->getQueryBuilder();
		$now = $this->timeFactory->getTime();

		$query->update('documentserver_sess')
			->set('last_seen', $query->createNamedParameter($now, \PDO::PARAM_INT))
			->set('user', $query->createNamedParameter($user))
			->set('user_original', $query->createNamedParameter($userOriginal))
			->set('readonly', $query->createNamedParameter($readOnly, \PDO::PARAM_INT))
			->where($query->expr()->eq('session_id', $query->createNamedParameter($session->getSessionId(), \PDO::PARAM_INT)));
		$query->execute();

		return new Session(
			$session->getSessionId(),
			$session->getDocumentId(),
			$user,
			$userOriginal,
			$now,
			$readOnly,
			$session->getUserIndex()
		);
	}

	public function markAsSeen(string $sessionId) {
		$query = $this->connection->getQueryBuilder();

		$query->update('documentserver_sess')
			->set('last_seen', $query->createNamedParameter($this->timeFactory->getTime(), \PDO::PARAM_INT))
			->where($query->expr()->eq('session_id', $query->createNamedParameter($sessionId)));
		$query->execute();
	}

	public function cleanSessions(): int {
		$query = $this->connection->getQueryBuilder();

		$cutoffTime = $this->timeFactory->getTime() - (Channel::TIMEOUT * 4);

		$query->delete('documentserver_sess')
			->where($query->expr()->lt('last_seen', $query->createNamedParameter($cutoffTime, \PDO::PARAM_INT)));
		return $query->execute();
	}

	public function isDocumentActive(int $documentId): bool {
		return count($this->getSessionsForDocument($documentId)) > 0;
	}

	/**
	 * @param int $documentId
	 * @return Session[]
	 */
	public function getSessionsForDocument(int $documentId): array {
		$query = $this->connection->getQueryBuilder();

		$query->select('session_id', 'document_id', 'user', 'user_original', 'last_seen', 'readonly', 'user_index')
			->from('documentserver_sess')
			->where($query->expr()->eq('document_id', $query->createNamedParameter($documentId, \PDO::PARAM_INT)));

		return array_map(function (array $row) {
			return Session::fromRow($row);
		}, $query->execute()->fetchAll());
	}

	public function getSessionForUser(string $userId): ?Session {
		$query = $this->connection->getQueryBuilder();

		$query->select('session_id', 'document_id', 'user', 'user_original', 'last_seen', 'readonly', 'user_index')
			->from('documentserver_sess')
			->where($query->expr()->eq($query->func()->concat('user', 'user_index'), $query->createNamedParameter($userId)));

		$row = $query->execute()->fetch();
		if ($row) {
			return Session::fromRow($row);
		} else {
			return null;
		}
	}
}
