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
 * The kind of actor of an audit entry: user, api_token, mcp_agent or system.
 *
 * The column is added not null with the default `user`, which SQLite and
 * PostgreSQL both accept as a plain column addition (SQLite cannot change a
 * column to not null in place). Existing entries are then backfilled: no
 * user_id is the system, an api_token_id is an API token, the rest stay a
 * user. Entries made through MCP before this migration cannot be told apart
 * from REST ones, so they stay api_token.
 *
 * The backfill needs the column, so it runs in postUp(), after the schema
 * change, and --dry-run neither shows nor runs it.
 */
final class Version20261112000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record the kind of actor on audit entries; entries made through MCP before this stay api_token';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('audit_entry')->addColumn('actor_kind', Types::STRING, ['length' => 16, 'default' => 'user']);
    }

    public function postUp(Schema $schema): void
    {
        // A dry run changes no schema, so the column is not there to fill.
        if (!\array_key_exists('actor_kind', $this->connection->createSchemaManager()->listTableColumns('audit_entry'))) {
            return;
        }

        $this->connection->executeStatement("UPDATE audit_entry SET actor_kind = 'system' WHERE user_id IS NULL");
        $this->connection->executeStatement("UPDATE audit_entry SET actor_kind = 'api_token' WHERE user_id IS NOT NULL AND api_token_id IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('audit_entry')->dropColumn('actor_kind');
    }
}
