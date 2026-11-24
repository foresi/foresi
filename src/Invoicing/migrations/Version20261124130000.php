<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The recurring template line an invoice line was issued from. No foreign
 * key: the template line may be removed later, and the id stays as it was.
 */
final class Version20261124130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the source line of an invoice line';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('invoice_line')->addColumn('source_line_id', Types::GUID, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('invoice_line')->dropColumn('source_line_id');
    }
}
