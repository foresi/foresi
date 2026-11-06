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
 * The export state of a document for the accountant, as two moments: when it was
 * last exported, and when it was first edited after that. Nothing stored so far was
 * exported, so both start empty.
 */
final class Version20261106010001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep when a document was exported for the accountant and changed after it';
    }

    public function up(Schema $schema): void
    {
        $invoice = $schema->getTable('invoice');
        $invoice->addColumn('exported_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $invoice->addColumn('changed_after_export_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $invoice = $schema->getTable('invoice');
        $invoice->dropColumn('changed_after_export_at');
        $invoice->dropColumn('exported_at');
    }
}
