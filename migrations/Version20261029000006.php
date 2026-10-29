<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Address book, step 2 of 3: the data move. isCustomer/isSupplier become
 * roles, the embedded address becomes one billing party_address per party, and
 * the new columns get their defaults. Plain SQL without schema changes, so the
 * statements run in order and are valid on SQLite and PostgreSQL (the JSON
 * values are untyped literals, which PostgreSQL coerces to the json column).
 */
final class Version20261029000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Address book: move roles and billing addresses, fill defaults';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE party SET roles = '[]', private_note = '', invoicing_due_days = 14, invoicing_currency = 'CZK', invoicing_language = 'cs', invoicing_payment_method = 'bank_transfer', invoicing_reverse_charge = FALSE");
        $this->addSql("UPDATE party SET roles = '[\"customer\"]' WHERE is_customer = TRUE AND is_supplier = FALSE");
        $this->addSql("UPDATE party SET roles = '[\"supplier\"]' WHERE is_customer = FALSE AND is_supplier = TRUE");
        $this->addSql("UPDATE party SET roles = '[\"customer\",\"supplier\"]' WHERE is_customer = TRUE AND is_supplier = TRUE");
        $this->addSql("INSERT INTO party_address (tenant_id, party_id, type, billing_flag, label, address_street, address_city, address_postal_code, address_country_code) SELECT tenant_id, id, 'billing', TRUE, '', address_street, address_city, address_postal_code, address_country_code FROM party");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE party SET is_customer = CASE WHEN CAST(roles AS TEXT) LIKE '%\"customer\"%' THEN TRUE ELSE FALSE END, is_supplier = CASE WHEN CAST(roles AS TEXT) LIKE '%\"supplier\"%' THEN TRUE ELSE FALSE END");
        foreach (['street', 'city', 'postal_code', 'country_code'] as $column) {
            $this->addSql(sprintf("UPDATE party SET address_%1\$s = COALESCE((SELECT a.address_%1\$s FROM party_address a WHERE a.party_id = party.id AND a.type = 'billing'), '')", $column));
        }
        $this->addSql('DELETE FROM party_address');
    }
}
