<?php

declare(strict_types=1);

namespace OCA\DocumentServer\Migration;

use Closure;
use Doctrine\DBAL\Schema\Schema;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000Date20191217135318 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var Schema $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('documentserver_sessions')) {
			$schema->dropTable('documentserver_sessions');
		}

		if (!$schema->hasTable('documentserver_sess')) {
			$table = $schema->createTable('documentserver_sess');
			$table->addColumn('session_id', 'string', [
				'notnull' => true,
				'length' => 16,
			]);
			$table->addColumn('document_id', 'bigint', [
				'notnull' => true,
				'length' => 6,
			]);
			$table->addColumn('user', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('user_original', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('last_seen', 'bigint', [
				'notnull' => true,
				'length' => 6
			]);
			$table->addColumn('readonly', 'boolean', [
				'notnull' => true
			]);
			$table->addColumn('user_index', 'bigint', [
				'notnull' => true,
				'length' => 6,
			]);
			$table->setPrimaryKey(['session_id']);
			$table->addIndex(['document_id'], 'documentserver_ses_doc');
			$table->addIndex(['last_seen'], 'documentserver_ses_last');
			$table->addIndex(['document_id', 'last_seen'], 'documentserver_ses_doc_last');
		}

		return $schema;
	}
}
