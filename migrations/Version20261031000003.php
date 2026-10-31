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
 * Keeps the comparison form of every e-mail address (lower case) in an
 * indexed column, so an import that matches contacts by e-mail looks each one
 * up instead of reading all addresses. The keys of stored addresses are filled
 * in PHP, which lower-cases letters with diacritics as the application does.
 */
final class Version20261031000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add party_email.address_key';
    }

    public function up(Schema $schema): void
    {
        $email = $schema->getTable('party_email');
        $email->addColumn('address_key', Types::STRING, ['length' => 254, 'default' => '']);
        $email->addIndex(['tenant_id', 'address_key'], 'idx_party_email_key');
    }

    public function postUp(Schema $schema): void
    {
        foreach ($this->connection->fetchAllKeyValue('SELECT id, address FROM party_email') as $id => $address) {
            $this->connection->update('party_email', ['address_key' => mb_strtolower(trim((string) $address))], ['id' => $id]);
        }
    }

    public function down(Schema $schema): void
    {
        $email = $schema->getTable('party_email');
        $email->dropIndex('idx_party_email_key');
        $email->dropColumn('address_key');
    }
}
