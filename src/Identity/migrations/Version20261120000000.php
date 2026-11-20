<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The baseline of Identity: its 8 tables as the global migrations left
 * them (ADR 0034). A database migrated before the module packages moves onto
 * it with app:migrations:rebase, which runs none of this.
 */
final class Version20261120000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the tables of Identity';
    }

    public function up(Schema $schema): void
    {
        $this->apiIdempotencyKey($schema);
        $this->apiToken($schema);
        $this->appUser($schema);
        $this->invitation($schema);
        $this->membership($schema);
        $this->oauthAuthorizationCode($schema);
        $this->oauthClient($schema);
        $this->oauthRefreshToken($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('oauth_refresh_token');
        $schema->dropTable('oauth_client');
        $schema->dropTable('oauth_authorization_code');
        $schema->dropTable('membership');
        $schema->dropTable('invitation');
        $schema->dropTable('app_user');
        $schema->dropTable('api_token');
        $schema->dropTable('api_idempotency_key');
    }

    private function apiIdempotencyKey(Schema $schema): void
    {
        $table = $schema->createTable('api_idempotency_key');
        $table->addColumn('token_id', Types::GUID);
        $table->addColumn('operation', Types::STRING, ['length' => 120]);
        $table->addColumn('idempotency_key', Types::STRING, ['length' => 255]);
        $table->addColumn('fingerprint', Types::STRING, ['length' => 64, 'fixed' => true]);
        $table->addColumn('status', Types::STRING, ['length' => 16]);
        $table->addColumn('response_status', Types::SMALLINT, ['notnull' => false]);
        $table->addColumn('response_headers', Types::TEXT, ['notnull' => false]);
        $table->addColumn('response_body', Types::TEXT, ['notnull' => false]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('completed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['token_id', 'operation', 'idempotency_key']);
        $table->addIndex(['created_at'], 'idx_api_idempotency_key_created');
    }

    private function apiToken(Schema $schema): void
    {
        $table = $schema->createTable('api_token');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('user_id', Types::GUID);
        $table->addColumn('name', Types::STRING, ['length' => 80]);
        $table->addColumn('scopes', Types::JSON);
        $table->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $table->addColumn('last_four', Types::STRING, ['length' => 4]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('last_used_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('revoked_by', Types::GUID, ['notnull' => false]);
        $table->addColumn('oauth_client_id', Types::GUID, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id'], 'idx_api_token_user');
        $table->addUniqueIndex(['token_hash'], 'uniq_api_token_hash');
    }

    private function appUser(Schema $schema): void
    {
        $table = $schema->createTable('app_user');
        $table->addColumn('email', Types::STRING, ['length' => 255]);
        $table->addColumn('display_name', Types::STRING, ['length' => 255]);
        $table->addColumn('locale', Types::STRING, ['length' => 2]);
        $table->addColumn('password_hash', Types::STRING, ['length' => 255]);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('totp_secret_sealed', Types::TEXT, ['notnull' => false]);
        $table->addColumn('recovery_code_hashes', Types::JSON, ['default' => '[]']);
        $table->addColumn('trusted_device_version', Types::INTEGER, ['default' => 0]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['email'], 'uniq_88bdf3e9e7927c74');
    }

    private function invitation(Schema $schema): void
    {
        $table = $schema->createTable('invitation');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('email', Types::STRING, ['length' => 255]);
        $table->addColumn('display_name', Types::STRING, ['length' => 255]);
        $table->addColumn('role', Types::STRING, ['length' => 32]);
        $table->addColumn('party_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('invited_by', Types::GUID);
        $table->addColumn('locale', Types::STRING, ['length' => 2]);
        $table->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('accepted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['party_id'], 'idx_f11d61a2213c1059');
        $table->addIndex(['email'], 'idx_invitation_email');
        $table->addUniqueIndex(['token_hash'], 'uniq_invitation_token');
        $table->addForeignKeyConstraint('party', ['party_id'], ['id'], [], 'fk_invitation_party');
    }

    private function membership(Schema $schema): void
    {
        $table = $schema->createTable('membership');
        $table->addColumn('role', Types::STRING, ['length' => 32]);
        $table->addColumn('id', Types::GUID);
        $table->addColumn('user_id', Types::GUID);
        $table->addColumn('party_id', Types::GUID, ['notnull' => false]);
        $table->addColumn('disabled_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('hourly_rate_minor', Types::BIGINT, ['notnull' => false]);
        $table->addColumn('hourly_rate_currency', Types::STRING, ['length' => 3, 'fixed' => true, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['party_id'], 'idx_86ffd285213c1059');
        $table->addUniqueIndex(['user_id'], 'uniq_membership_user');
        $table->addForeignKeyConstraint('party', ['party_id'], ['id'], [], 'fk_membership_party');
        $table->addForeignKeyConstraint('app_user', ['user_id'], ['id'], [], 'fk_membership_user');
    }

    private function oauthAuthorizationCode(Schema $schema): void
    {
        $table = $schema->createTable('oauth_authorization_code');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('code_hash', Types::STRING, ['length' => 64]);
        $table->addColumn('client_id', Types::GUID);
        $table->addColumn('user_id', Types::GUID);
        $table->addColumn('redirect_uri', Types::STRING, ['length' => 2000]);
        $table->addColumn('code_challenge', Types::STRING, ['length' => 128]);
        $table->addColumn('scopes', Types::JSON, ['platformOptions' => ['jsonb' => true]]);
        $table->addColumn('resource', Types::STRING, ['length' => 2048]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('used_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->addColumn('api_token_id', Types::GUID, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['code_hash'], 'uniq_oauth_authorization_code_hash');
    }

    private function oauthClient(Schema $schema): void
    {
        $table = $schema->createTable('oauth_client');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('client_id', Types::STRING, ['length' => 2048]);
        $table->addColumn('kind', Types::STRING, ['length' => 8]);
        $table->addColumn('name', Types::STRING, ['length' => 80]);
        $table->addColumn('redirect_uris', Types::JSON, ['platformOptions' => ['jsonb' => true]]);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('described_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['client_id'], 'uniq_oauth_client_client_id');
    }

    private function oauthRefreshToken(Schema $schema): void
    {
        $table = $schema->createTable('oauth_refresh_token');
        $table->addColumn('id', Types::GUID);
        $table->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $table->addColumn('api_token_id', Types::GUID);
        $table->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $table->addColumn('used_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['api_token_id'], 'idx_oauth_refresh_token_api_token');
        $table->addUniqueIndex(['token_hash'], 'uniq_oauth_refresh_token_hash');
    }
}
