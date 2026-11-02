<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Doctrine;

use App\Banking\Application\BankFeedDirectory;
use App\Banking\Application\FeedRoute;
use App\Tenancy\Infrastructure\Doctrine\TenantFilterBypass;
use Doctrine\DBAL\Connection;

/**
 * Plain SQL inside the sanctioned bypass: the token hash is the only key, and
 * no entity is loaded, so nothing of a tenant stays in the session.
 */
final readonly class DoctrineBankFeedDirectory implements BankFeedDirectory
{
    private const string ROUTE = <<<'SQL'
        SELECT f.tenant_id, f.id, f.firm_bank_account_id, a.legal_entity_id
        FROM bank_feed f
        JOIN firm_bank_account a ON a.id = f.firm_bank_account_id
        WHERE f.token_hash = :hash
        SQL;

    public function __construct(private Connection $connection, private TenantFilterBypass $bypass)
    {
    }

    public function route(string $tokenHash): ?FeedRoute
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $tokenHash)) {
            return null;
        }
        $row = $this->bypass->run(fn (): array|false => $this->connection->fetchAssociative(self::ROUTE, ['hash' => $tokenHash]));
        if (false === $row) {
            return null;
        }

        return new FeedRoute($this->text($row, 'tenant_id'), $this->text($row, 'id'), $this->text($row, 'firm_bank_account_id'), $this->text($row, 'legal_entity_id'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function text(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        return \is_string($value) ? trim($value) : throw new \UnexpectedValueException(\sprintf('The column %s of a bank feed is empty.', $column));
    }
}
