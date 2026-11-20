<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Exchange rate: its 1 table as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the table of Exchange rate';
    }

    public function up(Schema $schema): void
    {
        $this->exchangeRate($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('exchange_rate');
    }

    private function exchangeRate(Schema $schema): void
    {
        $table = $schema->createTable('exchange_rate');
        $table->addColumn('rate_date', Types::DATE_IMMUTABLE);
        $table->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $table->addColumn('amount', Types::INTEGER);
        $table->addColumn('rate', Types::DECIMAL, ['precision' => 18, 'scale' => 6]);
        $table->addColumn('published_on', Types::DATE_IMMUTABLE);
        $table->addColumn('source', Types::STRING, ['length' => 16]);
        $table->setPrimaryKey(['rate_date', 'currency']);
    }
}
