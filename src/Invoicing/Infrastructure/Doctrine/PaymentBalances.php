<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\InvoiceBalances;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * What is left to pay from the recorded payments: the payable amount less the
 * amounts and settlements of the allocations whose payment is not cancelled, as
 * InvoicePayments counts it, never below zero. An invoice in the trash owes
 * nothing, and so does a tax document on payment, paid by the payment it taxes.
 * It knows what was paid, so it is authoritative. In SQL, the same rule:
 * an invoice outside the trash whose payable exceeds what was paid.
 *
 * It reads what is committed, or written in the caller's transaction: under the
 * lock of the invoice, which recording and cancelling a payment take too, the
 * answer cannot change until the caller is done.
 */
final readonly class PaymentBalances implements InvoiceBalances, OwingInvoiceCondition
{
    private const string PAID = '(SELECT COALESCE(SUM(pa.amount_minor + pa.settlement_minor), 0) FROM payment_allocation pa'
        .' INNER JOIN payment pp ON pp.id = pa.payment_id AND pp.tenant_id = pa.tenant_id'
        .' WHERE pa.invoice_id = %1$s.id AND pa.tenant_id = %1$s.tenant_id AND pp.cancelled_at IS NULL)';

    public function __construct(private Connection $connection, private TenantContext $context)
    {
    }

    public function outstanding(array $invoiceIds): array
    {
        $ids = array_values(array_unique($invoiceIds));
        if ([] === $ids) {
            return [];
        }
        /** @var list<array{id: string, kind: string, payable_minor: int|string, paid_minor: int|string, currency: string, deleted_at: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.id, i.kind, i.payable_minor, i.currency, i.deleted_at, '.\sprintf(self::PAID, 'i').' AS paid_minor FROM invoice i WHERE i.tenant_id = :tenant AND i.id IN (:ids)',
            ['tenant' => $this->context->current()->toString(), 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
        $owed = [];
        foreach ($rows as $row) {
            $left = null === $row['deleted_at'] && DocumentKind::from($row['kind'])->isPayable() ? max(0, (int) $row['payable_minor'] - (int) $row['paid_minor']) : 0;
            $owed[$row['id']] = Money::ofMinor($left, Currency::from($row['currency']));
        }
        foreach ($ids as $id) {
            if (!isset($owed[$id])) {
                throw new UnknownInvoice();
            }
        }

        return $owed;
    }

    public function owing(string $invoice): string
    {
        return \sprintf('(%1$s.deleted_at IS NULL AND %1$s.kind <> \'%3$s\' AND %1$s.payable_minor > %2$s)', $invoice, \sprintf(self::PAID, $invoice), DocumentKind::PaymentTaxDocument->value);
    }

    public function isAuthoritative(): bool
    {
        return true;
    }
}
