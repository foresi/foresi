<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Timesheet: its 1 table as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the table of Timesheet';
    }

    public function up(Schema $schema): void
    {
        $this->releasedTime($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('released_time');
    }

    private function releasedTime(Schema $schema): void
    {
        $table = $schema->createTable('released_time');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('invoice_id', Types::GUID);
        $table->addColumn('time_entry_id', Types::GUID);
        $table->addColumn('invoice_line_id', Types::GUID);
        $table->addColumn('rate_minor', Types::BIGINT);
        $table->addColumn('rate_currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $table->addColumn('minutes', Types::INTEGER);
        $table->addColumn('fingerprint', Types::STRING, ['length' => 32]);
        $table->addColumn('released_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['invoice_id', 'time_entry_id'], 'uniq_released_time');
    }
}
