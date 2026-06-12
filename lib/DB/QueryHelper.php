<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2026
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

namespace OCA\DocumentServer\DB;

use OCP\DB\QueryBuilder\IQueryBuilder;

class QueryHelper {
	public static function fetchOne(IQueryBuilder $query) {
		if (method_exists($query, 'executeQuery')) {
			return $query->executeQuery()->fetchOne();
		}

		return $query->execute()->fetchColumn();
	}

	public static function fetchRow(IQueryBuilder $query) {
		if (method_exists($query, 'executeQuery')) {
			return $query->executeQuery()->fetchAssociative();
		}

		return $query->execute()->fetch();
	}

	public static function fetchAll(IQueryBuilder $query): array {
		if (method_exists($query, 'executeQuery')) {
			return $query->executeQuery()->fetchAllAssociative();
		}

		return $query->execute()->fetchAll();
	}

	public static function fetchFirstColumn(IQueryBuilder $query): array {
		if (method_exists($query, 'executeQuery')) {
			return $query->executeQuery()->fetchFirstColumn();
		}

		return $query->execute()->fetchAll(\PDO::FETCH_COLUMN);
	}

	public static function executeStatement(IQueryBuilder $query): int {
		if (method_exists($query, 'executeStatement')) {
			return $query->executeStatement();
		}

		return $query->execute();
	}
}
