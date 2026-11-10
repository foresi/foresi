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
 * Time released by an invoice in the trash: the entry, the line, the rate and the
 * minutes it was billed with and what the entry said then, for restoring the
 * invoice. The entry and the invoice are named by id without a foreign key, as the
 * entry may be deleted meanwhile and the restore has to tell. A new table, so
 * nothing existing needs a value.
 */
final class Version20261110140001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the time released by invoices in the trash';
    }

    public function up(Schema $schema): void
    {
        $released = $schema->createTable('released_time');
        $released->addColumn('id', Types::GUID);
        $released->addColumn('tenant_id', 'tenant_id');
        $released->addColumn('invoice_id', Types::GUID);
        $released->addColumn('time_entry_id', Types::GUID);
        $released->addColumn('invoice_line_id', Types::GUID);
        $released->addColumn('rate_minor', Types::BIGINT);
        $released->addColumn('rate_currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $released->addColumn('minutes', Types::INTEGER);
        $released->addColumn('fingerprint', Types::STRING, ['length' => 32]);
        $released->addColumn('released_at', Types::DATETIME_IMMUTABLE);
        $released->setPrimaryKey(['id']);
        $released->addIndex(['tenant_id'], 'idx_released_time_tenant');
        $released->addUniqueIndex(['invoice_id', 'time_entry_id'], 'uniq_released_time');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('released_time');
    }
}
