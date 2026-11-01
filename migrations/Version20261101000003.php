<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lets firms be archived and keeps a registration number unique among the active
 * firms of a tenant. The unique index covers active_registration_key, which is
 * null for an archived firm, so it ignores those; a partial index would not do,
 * as Doctrine cannot read one back from SQLite and schema validation fails there.
 * Every existing firm is active. The app has not written the table so far, so no
 * two can share a number; if a database has such rows by hand, the index refuses
 * them and the whole migration rolls back.
 *
 * The key is worked out here because SQLite has no regular expressions. Its rule
 * is repeated from RegistrationNumber::normalised(), as a record of how it was.
 * Plain SQL in up(), so --dry-run and --write-sql show what would run.
 */
final class Version20261101000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add legal_entity.archived_at and the unique registration key of active firms';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(\sprintf('ALTER TABLE legal_entity ADD archived_at %s DEFAULT NULL', $this->platform->getDateTimeTypeDeclarationSQL([])));
        $this->addSql('ALTER TABLE legal_entity ADD active_registration_key VARCHAR(192) DEFAULT NULL');

        foreach ($this->connection->fetchAllAssociative('SELECT id, registration_number FROM legal_entity') as $row) {
            $this->addSql('UPDATE legal_entity SET active_registration_key = ? WHERE id = ?', [
                mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]/u', '', (string) $row['registration_number'])),
                $row['id'],
            ]);
        }

        $this->addSql('CREATE UNIQUE INDEX uniq_legal_entity_active_registration ON legal_entity (tenant_id, registration_country, active_registration_key)');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('legal_entity');
        $table->dropIndex('uniq_legal_entity_active_registration');
        $table->dropColumn('active_registration_key');
        $table->dropColumn('archived_at');
    }
}
