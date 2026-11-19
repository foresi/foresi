<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119410001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Put a deleted recurring invoice that never issued in the trash instead of removing it';
    }

    public function up(Schema $schema): void
    {
        $recurring = $schema->getTable('recurring_invoice');
        $recurring->addColumn('deleted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $recurring->addColumn('deleted_by', Types::GUID, ['notnull' => false]);
        $recurring->addIndex(['deleted_at'], 'idx_recurring_invoice_deleted');
    }

    public function down(Schema $schema): void
    {
        $recurring = $schema->getTable('recurring_invoice');
        $recurring->dropIndex('idx_recurring_invoice_deleted');
        $recurring->dropColumn('deleted_by');
        $recurring->dropColumn('deleted_at');
    }
}
