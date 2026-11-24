<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Foresi\Banking\Application\BankFeedDirectory;
use Foresi\Banking\Application\FeedRoute;

/**
 * Plain SQL: the address id is the only key, and no entity is loaded. An
 * archived account takes in nothing, as it gets no address.
 */
final readonly class DoctrineBankFeedDirectory implements BankFeedDirectory
{
    private const string ROUTE = <<<'SQL'
        SELECT f.id, f.firm_bank_account_id
        FROM bank_feed f
        JOIN firm_bank_account a ON a.id = f.firm_bank_account_id AND a.archived_at IS NULL
        WHERE f.mail_inbound_address_id = :address
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function route(string $addressId): ?FeedRoute
    {
        $row = $this->connection->fetchAssociative(self::ROUTE, ['address' => $addressId]);
        if (false === $row) {
            return null;
        }

        return new FeedRoute($this->text($row, 'id'), $this->text($row, 'firm_bank_account_id'));
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
