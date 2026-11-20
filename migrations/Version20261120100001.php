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
 * One instance holds one firm (ADR 0039), so a firm is no longer archived:
 * with one firm the last active one could never be archived, and there was no
 * restore. The archive date goes, and with it the registration key of active
 * firms, which only kept two active firms from sharing a number. The guard
 * (Version20261118210001) made sure the database holds at most one firm; if
 * that firm was archived, it is the instance's firm again.
 *
 * Going down brings the columns back with the firm active.
 */
final class Version20261120100001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop legal_entity.archived_at and the registration key of active firms';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('legal_entity');
        $table->dropIndex('uniq_legal_entity_active_registration');
        $table->dropColumn('active_registration_key');
        $table->dropColumn('archived_at');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('legal_entity');
        $table->addColumn('archived_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('active_registration_key', Types::STRING, ['length' => 192, 'notnull' => false]);
        $table->addUniqueIndex(['registration_country', 'active_registration_key'], 'uniq_legal_entity_active_registration');
    }

    public function postDown(Schema $schema): void
    {
        foreach ($this->connection->fetchAllKeyValue('SELECT id, registration_number FROM legal_entity') as $id => $number) {
            $key = mb_strtoupper((string) preg_replace('/[^\p{L}\p{N}]/u', '', \is_string($number) ? $number : ''));
            $this->connection->executeStatement('UPDATE legal_entity SET active_registration_key = ? WHERE id = ?', [$key, $id]);
        }
    }
}
