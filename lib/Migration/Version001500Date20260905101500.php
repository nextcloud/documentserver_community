<?php

declare(strict_types=1);

namespace OCA\DocumentServer\Migration;

use Closure;
use Doctrine\DBAL\Schema\Schema;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop the indexes that are fully covered by another index on the same table.
 *
 * Every dropped index is a left-prefix of a wider one, so lookups keep the same
 * index while writes no longer have to maintain a second copy of it.
 */
class Version001500Date20260905101500 extends SimpleMigrationStep {
	/** table name => the redundant index on it */
	private const REDUNDANT_INDEXES = [
		// left-prefix of documentserver_change_proc (document_id, processing)
		'documentserver_changes' => 'documentserver_change_document',
		// left-prefix of documentserver_ses_doc_last (document_id, last_seen)
		'documentserver_sess' => 'documentserver_ses_doc',
		// left-prefix of documentserver_locks_doc_user (document_id, user)
		'documentserver_locks' => 'documentserver_locks_document',
	];

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var Schema $schema */
		$schema = $schemaClosure();
		$changed = false;

		foreach (self::REDUNDANT_INDEXES as $tableName => $indexName) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}

			$table = $schema->getTable($tableName);
			if ($table->hasIndex($indexName)) {
				$table->dropIndex($indexName);
				$changed = true;
			}
		}

		// MySQL and MariaDB append the clustered primary key to every secondary index,
		// so (session_id, message_id) is stored exactly like (session_id) there
		if ($schema->hasTable('documentserver_ipc')) {
			$table = $schema->getTable('documentserver_ipc');
			if ($table->hasIndex('documentserver_ipc_session')
				&& $table->getIndex('documentserver_ipc_session')->getColumns() !== ['session_id']) {
				$table->dropIndex('documentserver_ipc_session');
				$table->addIndex(['session_id'], 'documentserver_ipc_session');
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
