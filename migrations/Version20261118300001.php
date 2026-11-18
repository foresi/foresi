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
 * OAuth 2.1 sign-in of MCP clients (ADR 0036): the clients, the authorization
 * codes and the refresh tokens, and the link of an API token to the client it
 * connects. Codes and tokens are kept only as SHA-256 hashes.
 */
final class Version20261118300001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the OAuth clients, authorization codes and refresh tokens';
    }

    public function up(Schema $schema): void
    {
        $json = ['platformOptions' => ['jsonb' => true]];

        $clients = $schema->createTable('oauth_client');
        $clients->addColumn('id', Types::GUID);
        $clients->addColumn('client_id', Types::STRING, ['length' => 2048]);
        $clients->addColumn('kind', Types::STRING, ['length' => 8]);
        $clients->addColumn('name', Types::STRING, ['length' => 80]);
        $clients->addColumn('redirect_uris', Types::JSON, $json);
        $clients->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $clients->addColumn('described_at', Types::DATETIME_IMMUTABLE);
        $clients->setPrimaryKey(['id']);
        $clients->addUniqueIndex(['client_id'], 'uniq_oauth_client_client_id');

        $codes = $schema->createTable('oauth_authorization_code');
        $codes->addColumn('id', Types::GUID);
        $codes->addColumn('code_hash', Types::STRING, ['length' => 64]);
        $codes->addColumn('client_id', Types::GUID);
        $codes->addColumn('user_id', Types::GUID);
        $codes->addColumn('redirect_uri', Types::STRING, ['length' => 2000]);
        $codes->addColumn('code_challenge', Types::STRING, ['length' => 128]);
        $codes->addColumn('scopes', Types::JSON, $json);
        $codes->addColumn('resource', Types::STRING, ['length' => 2048]);
        $codes->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $codes->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $codes->addColumn('used_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $codes->addColumn('api_token_id', Types::GUID, ['notnull' => false]);
        $codes->setPrimaryKey(['id']);
        $codes->addUniqueIndex(['code_hash'], 'uniq_oauth_authorization_code_hash');

        $refresh = $schema->createTable('oauth_refresh_token');
        $refresh->addColumn('id', Types::GUID);
        $refresh->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $refresh->addColumn('api_token_id', Types::GUID);
        $refresh->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $refresh->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $refresh->addColumn('used_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $refresh->setPrimaryKey(['id']);
        $refresh->addUniqueIndex(['token_hash'], 'uniq_oauth_refresh_token_hash');
        $refresh->addIndex(['api_token_id'], 'idx_oauth_refresh_token_api_token');

        $schema->getTable('api_token')->addColumn('oauth_client_id', Types::GUID, ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('api_token')->dropColumn('oauth_client_id');
        $schema->dropTable('oauth_refresh_token');
        $schema->dropTable('oauth_authorization_code');
        $schema->dropTable('oauth_client');
    }
}
