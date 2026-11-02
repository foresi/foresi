<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\InvoiceBalances;
use App\Invoicing\Application\UnknownInvoice;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Without payments an invoice owes its whole payable amount, and nothing once it
 * is in the trash. It cannot tell a paid invoice from an open one, so it is not
 * authoritative.
 */
final readonly class DueDateBalances implements InvoiceBalances
{
    public function __construct(private Connection $connection, private TenantContext $context)
    {
    }

    public function outstanding(array $invoiceIds): array
    {
        $ids = array_values(array_unique($invoiceIds));
        if ([] === $ids) {
            return [];
        }
        /** @var list<array{id: string, payable_minor: int|string, currency: string, deleted_at: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, payable_minor, currency, deleted_at FROM invoice WHERE tenant_id = :tenant AND id IN (:ids)',
            ['tenant' => $this->context->current()->toString(), 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
        $owed = [];
        foreach ($rows as $row) {
            $currency = Currency::from($row['currency']);
            $owed[$row['id']] = Money::ofMinor(null === $row['deleted_at'] ? (int) $row['payable_minor'] : 0, $currency);
        }
        foreach ($ids as $id) {
            if (!isset($owed[$id])) {
                throw new UnknownInvoice();
            }
        }

        return $owed;
    }

    public function isAuthoritative(): bool
    {
        return false;
    }
}
