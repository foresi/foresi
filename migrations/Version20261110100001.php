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
 * Hourly rates: of a project, of a contact (shared by the firms or a firm's
 * own, as its other invoicing defaults) and of a person in the organisation.
 * Each is an amount in minor units with its currency, both null for none, so
 * nothing existing needs a value.
 */
final class Version20261110100001 extends AbstractMigration
{
    private const array TABLES = [
        'project' => 'hourly_rate',
        'party' => 'invoicing_hourly_rate',
        'party_firm_settings' => 'invoicing_hourly_rate',
        'membership' => 'hourly_rate',
    ];

    public function getDescription(): string
    {
        return 'Add the hourly rates of projects, contacts and people';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table => $prefix) {
            $columns = $schema->getTable($table);
            $columns->addColumn($prefix.'_minor', Types::BIGINT, ['notnull' => false]);
            $columns->addColumn($prefix.'_currency', Types::STRING, ['length' => 3, 'fixed' => true, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table => $prefix) {
            $columns = $schema->getTable($table);
            $columns->dropColumn($prefix.'_currency');
            $columns->dropColumn($prefix.'_minor');
        }
    }
}
