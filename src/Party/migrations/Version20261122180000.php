<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The VAT statuses of the firm over time (plan 33c): each from its day until
 * the next one, with the period length of a registered payer. One firm per
 * instance (ADR 0039), so no firm id.
 */
final class Version20261122180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the VAT statuses of the firm';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('party_vat_status_period');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('status', Types::STRING, ['length' => 32]);
        $table->addColumn('period_length', Types::STRING, ['length' => 16, 'notnull' => false]);
        $table->addColumn('valid_from', Types::DATE_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['valid_from'], 'uniq_party_vat_status_period_from');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('party_vat_status_period');
    }
}
