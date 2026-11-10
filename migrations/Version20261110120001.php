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
 * Invoiced time: besides the line that billed it, an entry keeps the invoice,
 * the rate it was billed at (minor units and currency) and the minutes billed
 * after rounding. All null for time not invoiced, which is all of it so far,
 * so nothing existing needs a value. The invoice is indexed for releasing its
 * time when it goes to the trash.
 */
final class Version20261110120001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the invoice, the billed rate and the billed minutes of time entries';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('time_entry');
        $table->addColumn('invoice_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('invoiced_rate_minor', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('invoiced_rate_currency', Types::STRING, ['length' => 3, 'fixed' => true, 'notnull' => false]);
        $table->addColumn('invoiced_minutes', Types::INTEGER, ['notnull' => false]);
        $table->addIndex(['invoice_id'], 'idx_time_entry_invoice');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('time_entry');
        $table->dropIndex('idx_time_entry_invoice');
        $table->dropColumn('invoiced_minutes');
        $table->dropColumn('invoiced_rate_currency');
        $table->dropColumn('invoiced_rate_minor');
        $table->dropColumn('invoice_id');
    }
}
