<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A recurring template gets a name, as iDoklad's "Název vzoru", and says whom a
 * sent invoice also goes to: a copy to the firm or the owner, and the contact's
 * addresses for a copy.
 *
 * Existing templates are named after their description, else after their
 * customer, as RecurringInvoice names a template saved without a
 * name. Both copies stay on, as every template sent them so far. The stored
 * delivery already is the "send to the customer" choice of the form: "send" is
 * ticked, "review" is not, so it is kept as it is.
 *
 * Plain SQL in up(), so --dry-run and --write-sql show what would run. The name
 * defaults to an empty string only to be added to a table that has rows.
 */
final class Version20261103000805 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recurring_invoice.name, copy_to_me and copy_to_others, named after the description';
    }

    public function up(Schema $schema): void
    {
        $true = $this->connection->getDatabasePlatform()->convertBooleans(true);
        $this->addSql("ALTER TABLE recurring_invoice ADD name VARCHAR(120) DEFAULT '' NOT NULL");
        $this->addSql(\sprintf('ALTER TABLE recurring_invoice ADD copy_to_me BOOLEAN DEFAULT %s NOT NULL', $true));
        $this->addSql(\sprintf('ALTER TABLE recurring_invoice ADD copy_to_others BOOLEAN DEFAULT %s NOT NULL', $true));
        // Line breaks and tabs become spaces, as a name is one line; the characters sit in the literals, which both databases take.
        $this->addSql("UPDATE recurring_invoice SET name = SUBSTR(TRIM(REPLACE(REPLACE(REPLACE(description, '\r', ' '), '\n', ' '), '\t', ' ')), 1, 120)");
        $this->addSql("UPDATE recurring_invoice SET name = SUBSTR((SELECT p.display_name FROM party p WHERE p.id = recurring_invoice.customer_id), 1, 120) WHERE name = ''");
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('recurring_invoice');
        $table->dropColumn('copy_to_others');
        $table->dropColumn('copy_to_me');
        $table->dropColumn('name');
    }
}
