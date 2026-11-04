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
 * A bank movement pays through at most one payment that is not cancelled. The
 * payment keeps the movement a second time, in live_bank_movement_id, only while
 * it is live: cancelling it sets that to NULL. A plain unique index over it then
 * holds the rule on SQLite and PostgreSQL alike, as primary_flag does for the
 * primary bank account, and leaves out cancelled payments and those without a
 * movement. No payment is stored by a bank movement yet, so there is nothing to
 * backfill and nothing that can break it.
 */
final class Version20261103030002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow one active payment per bank movement';
    }

    public function up(Schema $schema): void
    {
        $payment = $schema->getTable('payment');
        $payment->addColumn('live_bank_movement_id', Types::GUID, ['notnull' => false]);
        $payment->addUniqueIndex(['live_bank_movement_id'], 'uniq_payment_active_movement');
    }

    public function down(Schema $schema): void
    {
        $payment = $schema->getTable('payment');
        $payment->dropIndex('uniq_payment_active_movement');
        $payment->dropColumn('live_bank_movement_id');
    }
}
