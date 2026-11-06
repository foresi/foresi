<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where an imported payment came from and the run that brought it. Nothing stored
 * so far was imported, so every column starts empty, and empty columns repeat
 * freely under the unique index.
 */
final class Version20261106080001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the source and the run of an imported payment';
    }

    public function up(Schema $schema): void
    {
        $payment = $schema->getTable('payment');
        $payment->addColumn('import_source', Types::STRING, ['length' => 32, 'notnull' => false]);
        $payment->addColumn('import_external_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $payment->addColumn('import_run_id', Types::GUID, ['notnull' => false]);
        $payment->addColumn('imported_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $payment->addUniqueIndex(['legal_entity_id', 'import_source', 'import_external_id'], 'uniq_payment_import');
        $payment->addIndex(['import_run_id'], 'idx_payment_import_run');
    }

    public function down(Schema $schema): void
    {
        $payment = $schema->getTable('payment');
        $payment->dropIndex('idx_payment_import_run');
        $payment->dropIndex('uniq_payment_import');
        $payment->dropColumn('imported_at');
        $payment->dropColumn('import_run_id');
        $payment->dropColumn('import_external_id');
        $payment->dropColumn('import_source');
    }
}
