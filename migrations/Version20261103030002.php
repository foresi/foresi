<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A bank movement pays through at most one payment that is not cancelled. A
 * partial unique index, which SQLite and PostgreSQL both have: cancelled
 * payments and payments without a movement are left out of it. No payment is
 * stored by a bank movement yet, so nothing can break it.
 */
final class Version20261103030002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow one active payment per bank movement';
    }

    public function up(Schema $schema): void
    {
        // Written out, in the form PostgreSQL gives the condition back, so the mapping matches both databases.
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_active_movement ON payment (bank_movement_id) WHERE (cancelled_at IS NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_payment_active_movement');
    }
}
