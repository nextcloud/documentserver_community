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

namespace OCA\DocumentServer\IPC;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class DatabaseIPCBackend implements IIPCBackend {
	const TIMEOUT = 120;

	private $connection;
	private $timeFactory;

	public function __construct(IDBConnection $connection, ITimeFactory $timeFactory) {
		$this->connection = $connection;
		$this->timeFactory = $timeFactory;
	}

	public function initChannel(string $channel) {
		// noop
	}

	public function cleanupChannel(string $channel) {
		$query = $this->connection->getQueryBuilder();
		$query->delete('documentserver_ipc')
			->where($query->expr()->eq('session_id', $query->createNamedParameter($channel)));
		$query->execute();
	}

	public function pushMessage(string $channel, string $message) {
		$query = $this->connection->getQueryBuilder();
		$query->insert('documentserver_ipc')
			->values([
				'session_id' => $query->createNamedParameter($channel),
				'time' => $query->createNamedParameter($this->timeFactory->getTime(), IQueryBuilder::PARAM_INT),
				'message' => $query->createNamedParameter($message),
			]);
		$query->execute();
	}

	public function popMessage(string $channel, int $timeout): ?string {
		$query = $this->connection->getQueryBuilder();
		$query->select('message_id', 'message')
			->from('documentserver_ipc')
			->where($query->expr()->eq("session_id", $query->createNamedParameter($channel)))
			->orderBy('message_id', 'ASC')
			->setMaxResults(1);

		if ($row = $query->execute()->fetch()) {
			$query = $this->connection->getQueryBuilder();
			$query->delete('documentserver_ipc')
				->where($query->expr()->eq('message_id', $query->createNamedParameter($row['message_id'], IQueryBuilder::PARAM_INT)));
			$deleted = $query->execute();

			// if we didn't delete the row there was a race we lost, so we'll try again later
			if ($deleted === 1) {
				return $row['message'];
			}
		}

		return null;
	}

	public function expireMessages() {
		// if a message hasn't been popped for 2min, we can assume that nobody is listening
		$query = $this->connection->getQueryBuilder();
		$query->delete('documentserver_ipc')
			->where($query->expr()->lt('time', $query->createNamedParameter($this->timeFactory->getTime() - self::TIMEOUT, IQueryBuilder::PARAM_INT)));
		$query->execute();
	}
}

