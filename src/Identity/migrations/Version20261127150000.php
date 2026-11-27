<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Foresi\Shared\Infrastructure\Doctrine\RunsOnlyForReal;

/**
 * The scopes the tokens hold follow the modules that declare them. The deals'
 * scope is deals, no longer sales: sales:read becomes deals:read and
 * sales:write deals:write. The firm has a scope of its own, firm, which Party
 * declares, while contacts is the address book's: a token that read the firm
 * with contacts:read keeps reading it, so contacts:read also gets firm:read and
 * contacts:write firm:write. The personal API tokens, which the OAuth clients'
 * tokens are, and the authorization codes not yet exchanged are rewritten.
 * Going down gives back the sales scopes and takes the firm scopes away.
 */
final class Version20261127150000 extends AbstractMigration implements RunsOnlyForReal
{
    private const array TABLES = ['api_token', 'oauth_authorization_code'];
    private const array RENAMED = ['sales:read' => 'deals:read', 'sales:write' => 'deals:write'];
    private const array ADDED = ['contacts:read' => 'firm:read', 'contacts:write' => 'firm:write'];

    public function getDescription(): string
    {
        return 'Rename the scope sales to deals, and give the tokens with contacts the scope firm';
    }

    public function up(Schema $schema): void
    {
        // The rows change in postUp().
    }

    public function postUp(Schema $schema): void
    {
        $this->rewrite(static function (array $scopes): array {
            $rewritten = [];
            foreach ($scopes as $scope) {
                $rewritten[] = self::RENAMED[$scope] ?? $scope;
                if (isset(self::ADDED[$scope])) {
                    $rewritten[] = self::ADDED[$scope];
                }
            }

            return $rewritten;
        });
    }

    public function down(Schema $schema): void
    {
        // The rows change in postDown().
    }

    public function postDown(Schema $schema): void
    {
        $back = array_flip(self::RENAMED);
        $this->rewrite(static fn (array $scopes): array => array_map(
            static fn (string $scope): string => $back[$scope] ?? $scope,
            array_values(array_filter($scopes, static fn (string $scope): bool => !\in_array($scope, self::ADDED, true))),
        ));
    }

    /**
     * @param \Closure(list<string>): list<string> $change
     */
    private function rewrite(\Closure $change): void
    {
        foreach (self::TABLES as $table) {
            if (!$this->connection->createSchemaManager()->tablesExist([$table])) {
                continue;
            }
            foreach ($this->connection->fetchAllAssociative(\sprintf('SELECT id, scopes FROM %s', $table)) as $row) {
                $scopes = $this->scopes($row['scopes']);
                if (null === $scopes) {
                    continue;
                }
                $changed = array_values(array_unique($change($scopes)));
                if ($changed !== $scopes) {
                    $this->connection->update($table, ['scopes' => json_encode($changed, \JSON_THROW_ON_ERROR)], ['id' => $row['id']]);
                }
            }
        }
    }

    /**
     * @return list<string>|null null for a value that is no list of scopes
     */
    private function scopes(mixed $json): ?array
    {
        $scopes = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($scopes) || !array_is_list($scopes)) {
            return null;
        }
        $names = [];
        foreach ($scopes as $scope) {
            if (!\is_string($scope)) {
                return null;
            }
            $names[] = $scope;
        }

        return $names;
    }
}
