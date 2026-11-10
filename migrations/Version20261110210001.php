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
 * Exchange rates to CZK, one row per currency and date asked for, with the day
 * the rate was published and its source. Global to the installation, so there is
 * no tenant column. A new table, so nothing existing needs a value.
 */
final class Version20261110210001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the exchange rates';
    }

    public function up(Schema $schema): void
    {
        $rates = $schema->createTable('exchange_rate');
        $rates->addColumn('rate_date', Types::DATE_IMMUTABLE);
        $rates->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $rates->addColumn('amount', Types::INTEGER);
        $rates->addColumn('rate', Types::DECIMAL, ['precision' => 18, 'scale' => 6]);
        $rates->addColumn('published_on', Types::DATE_IMMUTABLE);
        $rates->addColumn('source', Types::STRING, ['length' => 16]);
        $rates->setPrimaryKey(['rate_date', 'currency']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('exchange_rate');
    }
}
