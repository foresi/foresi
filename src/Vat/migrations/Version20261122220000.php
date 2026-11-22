<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of the VAT module: where the firm pays its VAT, one row with a
 * constant key (ADR 0039: one firm per instance).
 */
final class Version20261122220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the VAT payment settings';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('vat_settings');
        $table->addColumn('id', Types::SMALLINT);
        $table->addColumn('office_account', Types::STRING, ['length' => 64]);
        $table->addColumn('variable_symbol', Types::STRING, ['length' => 35]);
        $table->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('vat_settings');
    }
}
