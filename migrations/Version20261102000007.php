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
 * The payment details a sent document showed, frozen with it, and the moment a
 * stuck send was last queued again. Rows from before carry a bank transfer with
 * no account, so their page shows no payment block; sending is not released yet.
 */
final class Version20261102000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Freeze the payment details of a send and remember requeueing';
    }

    public function up(Schema $schema): void
    {
        $send = $schema->getTable('invoice_send');
        $send->addColumn('requeued_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $send->addColumn('payment_method', Types::STRING, ['length' => 32, 'default' => 'bank_transfer']);
        $send->addColumn('bank_account', Types::STRING, ['length' => 34, 'notnull' => false]);
        $send->addColumn('domestic_account', Types::STRING, ['length' => 64, 'notnull' => false]);
        $send->addColumn('variable_symbol', Types::STRING, ['length' => 10, 'default' => '']);
        $send->addColumn('payment_code', Types::TEXT, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $send = $schema->getTable('invoice_send');
        foreach (['requeued_at', 'payment_method', 'bank_account', 'domestic_account', 'variable_symbol', 'payment_code'] as $column) {
            $send->dropColumn($column);
        }
    }
}
