<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Address book fix, step 2 of 3: the data move. Registration keys are computed
 * in PHP (letters and digits of any script, upper case, as
 * RegistrationNumber::normalised does), primary accounts get their flag, and
 * the party e-mail becomes a general party_email row. Going down copies the
 * first general e-mail back and deletes the general e-mail rows, so e-mails
 * added after the migration are lost.
 */
final class Version20261029000009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Address book: fill registration keys, primary flags and move party e-mails to party_email';
    }

    public function up(Schema $schema): void
    {
        /** @var list<array{id: int|string, registration_number: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, registration_number FROM party WHERE registration_number IS NOT NULL');
        foreach ($rows as $row) {
            $key = mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]/u', '', $row['registration_number']));
            if ('' !== $key) {
                $this->addSql('UPDATE party SET registration_key = ? WHERE id = ?', [$key, $row['id']]);
            }
        }
        $this->addSql('UPDATE party_bank_account SET primary_flag = TRUE WHERE is_primary = TRUE');
        $this->addSql("INSERT INTO party_email (tenant_id, party_id, address, purpose) SELECT tenant_id, id, email, 'general' FROM party WHERE email <> ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE party SET email = COALESCE((SELECT e.address FROM party_email e WHERE e.party_id = party.id AND e.purpose = 'general' ORDER BY e.id LIMIT 1), '')");
        $this->addSql("DELETE FROM party_email WHERE purpose = 'general'");
        $this->addSql('UPDATE party_bank_account SET is_primary = CASE WHEN primary_flag IS NULL THEN FALSE ELSE TRUE END');
    }
}
