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
 * What a person chose about tax documents on the payments of an advance invoice.
 * NULL follows the rules, which is what every stored document does.
 */
final class Version20261105030001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Let an advance invoice say whether its payments get tax documents';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('invoice')->addColumn('tax_on_receipt', Types::STRING, ['length' => 8, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('invoice')->dropColumn('tax_on_receipt');
    }
}
