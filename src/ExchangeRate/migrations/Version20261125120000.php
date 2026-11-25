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
 * Several rate sources side by side (ADR 0046): a stored quote is keyed by its
 * source as well and says which currency it is priced in. Every quote stored
 * so far is the ČNB's, in CZK. The firm's choice of a source is one row with a
 * constant key (ADR 0039).
 */
final class Version20261125120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Key the exchange rates by their source, keep their base, and add the firm\'s choice of a source';
    }

    public function up(Schema $schema): void
    {
        $rates = $schema->getTable('exchange_rate');
        $rates->addColumn('base', Types::STRING, ['length' => 3, 'fixed' => true, 'default' => 'CZK']);
        $rates->dropPrimaryKey();
        $rates->setPrimaryKey(['rate_date', 'currency', 'source']);

        $setting = $schema->createTable('exchange_rate_setting');
        $setting->addColumn('id', Types::SMALLINT);
        $setting->addColumn('source', Types::STRING, ['length' => 16]);
        $setting->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('exchange_rate_setting');
        $rates = $schema->getTable('exchange_rate');
        $rates->dropPrimaryKey();
        $rates->setPrimaryKey(['rate_date', 'currency']);
        $rates->dropColumn('base');
    }
}
