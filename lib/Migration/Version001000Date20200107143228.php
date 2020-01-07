<?php

declare(strict_types=1);

namespace OCA\DocumentServer\Migration;

use Closure;
use Doctrine\DBAL\Schema\Schema;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000Date20200107143228 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var Schema $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('documentserver_changes')) {
			$table = $schema->getTable('documentserver_changes');
			$table->addColumn('change_index', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 6,
			]);

			$table->addUniqueIndex(['change_index', 'document_id'], 'documentserver_change_doc_id');
		}

		return $schema;
	}
}
