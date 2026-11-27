<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Migrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The invoicing defaults of a party move from the party to a table of
 * Invoicing, one row for each party (invoicing_party_default). Where the party
 * still holds them, every party's values are copied over; a fresh install,
 * whose party never had them, starts empty. Version20261127130001 then takes
 * them off the party.
 *
 * Going down drops the table; the values go back onto the party first, where
 * Version20261127130001 going down gave it the columns again.
 */
final class Version20261127130000 extends AbstractMigration
{
    /** The columns of party the defaults are copied from, by their column here. */
    private const array PARTY_COLUMNS = [
        'due_days' => 'invoicing_due_days',
        'currency' => 'invoicing_currency',
        'language' => 'invoicing_language',
        'payment_method' => 'invoicing_payment_method',
        'reverse_charge' => 'invoicing_reverse_charge',
        'send_reminders' => 'invoicing_send_reminders',
        'hourly_rate_minor' => 'invoicing_hourly_rate_minor',
        'hourly_rate_currency' => 'invoicing_hourly_rate_currency',
    ];

    /** @var array<string, string> the columns the party still has, as PARTY_COLUMNS lists them */
    private array $partyColumns = [];

    public function getDescription(): string
    {
        return 'Keep the invoicing defaults of a party in a table of Invoicing';
    }

    public function up(Schema $schema): void
    {
        // Party's migrations create the party before this runs; a reader of the migrations alone may not have it.
        $party = $schema->hasTable('party') ? $schema->getTable('party') : null;
        $this->partyColumns = $party instanceof \Doctrine\DBAL\Schema\Table ? array_filter(self::PARTY_COLUMNS, $party->hasColumn(...)) : [];

        $table = $schema->createTable('invoicing_party_default');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('party_id', Types::GUID);
        $table->addColumn('due_days', Types::INTEGER);
        $table->addColumn('currency', Types::STRING, ['length' => 3, 'fixed' => true]);
        $table->addColumn('language', Types::STRING, ['length' => 2, 'fixed' => true]);
        $table->addColumn('payment_method', Types::STRING, ['length' => 32]);
        $table->addColumn('reverse_charge', Types::BOOLEAN);
        $table->addColumn('send_reminders', Types::BOOLEAN, ['default' => true]);
        $table->addColumn('hourly_rate_minor', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('hourly_rate_currency', Types::STRING, ['length' => 3, 'fixed' => true, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['party_id'], 'uniq_f24fc6d213c1059');
        $table->addForeignKeyConstraint('party', ['party_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_invoicing_party_default_party');
    }

    public function postUp(Schema $schema): void
    {
        // The rows are copied once the table exists, the same on SQLite and PostgreSQL; a row
        // takes the party's id as its own. The party had every column or none of them.
        if (\count($this->partyColumns) !== \count(self::PARTY_COLUMNS)) {
            return;
        }
        $this->connection->executeStatement(\sprintf(
            'INSERT INTO invoicing_party_default (id, party_id, %s) SELECT id, id, %s FROM party',
            implode(', ', array_keys(self::PARTY_COLUMNS)),
            implode(', ', self::PARTY_COLUMNS),
        ));
    }

    public function preDown(Schema $schema): void
    {
        if (!$schema->hasTable('party') || !$schema->getTable('party')->hasColumn('invoicing_due_days')) {
            return;
        }
        foreach ($this->connection->fetchAllAssociative('SELECT party_id, '.implode(', ', array_keys(self::PARTY_COLUMNS)).' FROM invoicing_party_default') as $row) {
            $values = [];
            foreach (self::PARTY_COLUMNS as $column => $partyColumn) {
                $values[$partyColumn] = \in_array($column, ['reverse_charge', 'send_reminders'], true) ? (bool) $row[$column] : $row[$column];
            }
            $this->connection->update('party', $values, ['id' => $row['party_id']], [
                'invoicing_reverse_charge' => ParameterType::BOOLEAN,
                'invoicing_send_reminders' => ParameterType::BOOLEAN,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('invoicing_party_default');
    }
}
