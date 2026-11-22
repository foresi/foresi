<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Foresi\Shared\Domain\Id;

/**
 * The VAT status the firm kept becomes its first VAT status over time: one
 * undated stretch, a registered payer by the month, as the day it began is not
 * known. A database that still holds several firms takes the oldest one, the
 * firm of the instance. Without a firm, or with stretches already, nothing changes.
 */
final class Version20261122180001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Turn the kept VAT status of the firm into its first VAT status over time';
    }

    public function up(Schema $schema): void
    {
        // The data moves in postUp(), once the table exists: nothing is read while migrations are planned.
    }

    public function postUp(Schema $schema): void
    {
        $this->connection->executeStatement(
            'INSERT INTO party_vat_status_period (id, status, period_length, valid_from) '
            ."SELECT ?, vat_status, CASE WHEN vat_status = 'registered' THEN 'month' ELSE NULL END, NULL FROM legal_entity "
            .'WHERE NOT EXISTS (SELECT 1 FROM party_vat_status_period) ORDER BY id LIMIT 1',
            [Id::generate()],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM party_vat_status_period WHERE valid_from IS NULL');
    }
}
