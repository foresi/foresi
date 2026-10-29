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
 * the trimmed party e-mail becomes a general party_email row (blank and
 * over-long values are skipped), only the oldest primary account per party keeps
 * its flag, and registration numbers without a letter or digit are cleared. Going down copies the
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
            if ('' === $key) {
                // Unreadable (no letter or digit): the domain no longer accepts such a number.
                $this->addSql('UPDATE party SET registration_country = NULL, registration_number = NULL WHERE id = ?', [$row['id']]);
            } else {
                $this->addSql('UPDATE party SET registration_key = ? WHERE id = ?', [$key, $row['id']]);
            }
        }

        // At most one primary account per party: the oldest keeps it.
        $this->addSql('UPDATE party_bank_account SET primary_flag = TRUE WHERE is_primary = TRUE AND id = (SELECT MIN(b.id) FROM party_bank_account b WHERE b.party_id = party_bank_account.party_id AND b.is_primary = TRUE)');

        /** @var list<array{id: int|string, tenant_id: string, email: string}> $parties */
        $parties = $this->connection->fetchAllAssociative("SELECT id, tenant_id, email FROM party WHERE email <> ''");
        foreach ($parties as $party) {
            $address = trim($party['email']);
            if ('' !== $address && mb_strlen($address) <= 254) {
                $this->addSql("INSERT INTO party_email (tenant_id, party_id, address, purpose) VALUES (?, ?, ?, 'general')", [$party['tenant_id'], $party['id'], $address]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE party SET email = COALESCE((SELECT e.address FROM party_email e WHERE e.party_id = party.id AND e.purpose = 'general' ORDER BY e.id LIMIT 1), '')");
        $this->addSql("DELETE FROM party_email WHERE purpose = 'general'");
        $this->addSql('UPDATE party_bank_account SET is_primary = CASE WHEN primary_flag IS NULL THEN FALSE ELSE TRUE END');
    }
}
