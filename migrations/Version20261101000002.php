<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Records the aggregate root on every audit entry, so one query returns the
 * whole history of a document or contact, children removed since included.
 *
 * Existing entries are their own root, except the ones of addresses, e-mails
 * and bank accounts (root: their party) and contact links (root: their company)
 * whose row still exists. A child that is gone keeps its own id as root: the
 * log is not rewritten from guesses. Classes are named as literals, as a
 * record of how the data looked then.
 *
 * Plain SQL in up(), so --dry-run and --write-sql show what would run. The
 * columns default to an empty string only to be added to a table that has rows.
 */
final class Version20261101000002 extends AbstractMigration
{
    private const string PARTY = 'App\Party\Domain\Party';

    /** @var array<string, array{string, string}> entity class => table, column holding its root */
    private const array CHILDREN = [
        'App\Party\Domain\PartyAddress' => ['party_address', 'party_id'],
        'App\Party\Domain\PartyEmail' => ['party_email', 'party_id'],
        'App\Party\Domain\PartyBankAccount' => ['party_bank_account', 'party_id'],
        'App\Party\Domain\ContactLink' => ['contact_link', 'company_id'],
    ];

    public function getDescription(): string
    {
        return 'Add audit_entry.root_class and root_id, with their index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE audit_entry ADD root_class VARCHAR(255) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE audit_entry ADD root_id VARCHAR(64) DEFAULT '' NOT NULL");
        $this->addSql('UPDATE audit_entry SET root_class = entity_class, root_id = entity_id');

        foreach (self::CHILDREN as $class => [$table, $column]) {
            // One join, not a subquery per audit row: a correlated one scans the child table for each of them on PostgreSQL.
            // UPDATE ... FROM needs SQLite 3.33. CAST: the ids are UUIDs on PostgreSQL and text in the audit log.
            $this->addSql(sprintf(
                "UPDATE audit_entry AS a SET root_class = '%1\$s', root_id = CAST(c.%3\$s AS VARCHAR(64))"
                ." FROM %2\$s c WHERE a.entity_class = '%4\$s' AND a.entity_id = CAST(c.id AS VARCHAR(64))",
                self::PARTY,
                $table,
                $column,
                $class,
            ));
        }

        $this->addSql('CREATE INDEX idx_audit_entry_root ON audit_entry (tenant_id, root_class, root_id)');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('audit_entry');
        $table->dropIndex('idx_audit_entry_root');
        $table->dropColumn('root_class');
        $table->dropColumn('root_id');
    }
}
