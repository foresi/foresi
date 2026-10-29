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
 * Address book, step 3 of 3: the new party columns become required and the
 * replaced ones (is_customer, is_supplier, the embedded address) are dropped.
 * Going down re-adds them empty (nullable) for the previous migration to fill.
 */
final class Version20261029000007 extends AbstractMigration
{
    private const array REQUIRED = ['roles', 'private_note', 'invoicing_due_days', 'invoicing_currency', 'invoicing_language', 'invoicing_payment_method', 'invoicing_reverse_charge'];
    private const array ADDRESS = ['address_street', 'address_city', 'address_postal_code', 'address_country_code'];

    public function getDescription(): string
    {
        return 'Address book: require the new party columns and drop is_customer, is_supplier and the embedded address';
    }

    public function up(Schema $schema): void
    {
        $party = $schema->getTable('party');
        foreach (self::REQUIRED as $column) {
            $party->getColumn($column)->setNotnull(true);
        }
        foreach (['is_customer', 'is_supplier', ...self::ADDRESS] as $column) {
            $party->dropColumn($column);
        }
    }

    public function down(Schema $schema): void
    {
        $party = $schema->getTable('party');
        foreach (self::REQUIRED as $column) {
            $party->getColumn($column)->setNotnull(false);
        }
        $party->addColumn('is_customer', Types::BOOLEAN, ['notnull' => false]);
        $party->addColumn('is_supplier', Types::BOOLEAN, ['notnull' => false]);
        $party->addColumn('address_street', Types::STRING, ['length' => 255, 'notnull' => false]);
        $party->addColumn('address_city', Types::STRING, ['length' => 128, 'notnull' => false]);
        $party->addColumn('address_postal_code', Types::STRING, ['length' => 32, 'notnull' => false]);
        $party->addColumn('address_country_code', Types::STRING, ['length' => 2, 'fixed' => true, 'notnull' => false]);
    }
}
