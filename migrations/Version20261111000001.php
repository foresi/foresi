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
 * Personal API tokens, kept as the SHA-256 of their secret, and the token an
 * audit entry was written through. A new table and a nullable column, so
 * nothing existing needs a value.
 */
final class Version20261111000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add personal API tokens and the token of audit entries';
    }

    public function up(Schema $schema): void
    {
        $tokens = $schema->createTable('api_token');
        $tokens->addColumn('id', Types::GUID);
        $tokens->addColumn('tenant_id', 'tenant_id');
        $tokens->addColumn('user_id', Types::GUID);
        $tokens->addColumn('name', Types::STRING, ['length' => 80]);
        $tokens->addColumn('scopes', Types::JSON);
        $tokens->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $tokens->addColumn('last_four', Types::STRING, ['length' => 4]);
        $tokens->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $tokens->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $tokens->addColumn('last_used_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $tokens->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $tokens->addColumn('revoked_by', Types::GUID, ['notnull' => false]);
        $tokens->setPrimaryKey(['id']);
        $tokens->addIndex(['tenant_id'], 'idx_api_token_tenant');
        $tokens->addIndex(['tenant_id', 'user_id'], 'idx_api_token_user');
        $tokens->addUniqueIndex(['token_hash'], 'uniq_api_token_hash');

        $schema->getTable('audit_entry')->addColumn('api_token_id', Types::GUID, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('audit_entry')->dropColumn('api_token_id');
        $schema->dropTable('api_token');
    }
}
