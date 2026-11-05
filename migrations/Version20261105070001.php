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
 * Whether automatic reminders take advance invoices too. Off, as every firm has
 * had them for invoices only so far.
 */
final class Version20261105070001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let a firm remind advance invoices';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('firm_reminders')->addColumn('remind_advances', Types::BOOLEAN, ['default' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('firm_reminders')->dropColumn('remind_advances');
    }
}
