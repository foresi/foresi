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
 * Takes the invoicing defaults off the party, once Version20261127130000 has
 * copied them to invoicing_party_default. A fresh install's party never had
 * them. Going down gives the party the columns again, with the standard
 * defaults until Version20261127130000 going down puts the values back.
 */
final class Version20261127130001 extends AbstractMigration
{
    private const array COLUMNS = [
        'invoicing_due_days',
        'invoicing_currency',
        'invoicing_language',
        'invoicing_payment_method',
        'invoicing_reverse_charge',
        'invoicing_send_reminders',
        'invoicing_hourly_rate_minor',
        'invoicing_hourly_rate_currency',
    ];

    public function getDescription(): string
    {
        return 'Take the invoicing defaults off the party';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('party')) {
            return;
        }
        $party = $schema->getTable('party');
        foreach (self::COLUMNS as $column) {
            if ($party->hasColumn($column)) {
                $party->dropColumn($column);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $party = $schema->getTable('party');
        $party->addColumn('invoicing_due_days', Types::INTEGER, ['default' => 14]);
        $party->addColumn('invoicing_currency', Types::STRING, ['length' => 3, 'fixed' => true, 'default' => 'CZK']);
        $party->addColumn('invoicing_language', Types::STRING, ['length' => 2, 'fixed' => true, 'default' => 'cs']);
        $party->addColumn('invoicing_payment_method', Types::STRING, ['length' => 32, 'default' => 'bank_transfer']);
        $party->addColumn('invoicing_reverse_charge', Types::BOOLEAN, ['default' => false]);
        $party->addColumn('invoicing_send_reminders', Types::BOOLEAN, ['default' => true]);
        $party->addColumn('invoicing_hourly_rate_minor', Types::BIGINT, ['notnull' => false]);
        $party->addColumn('invoicing_hourly_rate_currency', Types::STRING, ['length' => 3, 'fixed' => true, 'notnull' => false]);
    }
}
