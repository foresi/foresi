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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * What is left to pay from the recorded payments: the payable amount plus the
 * payable of its corrective documents outside the trash, less the amounts and
 * settlements of the allocations whose payment is not cancelled, as InvoicePayments
 * counts it, never below zero. An invoice in the trash owes nothing, and so do a
 * tax document on payment, paid by the payment it taxes, and a corrective
 * document, whose money follows its invoice. It knows what was paid, so it is
 * authoritative. In SQL, the same rule: an invoice outside the trash whose payable
 * and corrections exceed what was paid.
 *
 * It reads what is committed, or written in the caller's transaction: under the
 * lock of the invoice, which recording and cancelling a payment take too, the
 * answer cannot change until the caller is done.
 */
final readonly class PaymentBalances implements InvoiceBalances, OwingInvoiceCondition
{
    private const string PAID = '(SELECT COALESCE(SUM(pa.amount_minor + pa.settlement_minor), 0) FROM payment_allocation pa'
        .' INNER JOIN payment pp ON pp.id = pa.payment_id'
        .' WHERE pa.invoice_id = %1$s.id AND pp.cancelled_at IS NULL)';

    private const string CORRECTIONS = '(SELECT COALESCE(SUM(pc.payable_minor), 0) FROM invoice pc'
        .' WHERE pc.corrected_invoice_id = %1$s.id AND pc.deleted_at IS NULL)';

    public function __construct(private Connection $connection)
    {
    }

    public function outstanding(array $invoiceIds): array
    {
        $ids = array_values(array_unique($invoiceIds));
        if ([] === $ids) {
            return [];
        }
        /** @var list<array{id: string, kind: string, payable_minor: int|string, paid_minor: int|string, corrections_minor: int|string, currency: string, deleted_at: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.id, i.kind, i.payable_minor, i.currency, i.deleted_at, '.\sprintf(self::PAID, 'i').' AS paid_minor, '.\sprintf(self::CORRECTIONS, 'i').' AS corrections_minor'
            .' FROM invoice i WHERE i.id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );
        $owed = [];
        foreach ($rows as $row) {
            $left = null === $row['deleted_at'] && DocumentKind::from($row['kind'])->isPayable() ? max(0, (int) $row['payable_minor'] + (int) $row['corrections_minor'] - (int) $row['paid_minor']) : 0;
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
        return \sprintf(
            '(%1$s.deleted_at IS NULL AND %1$s.kind NOT IN (\'%4$s\', \'%5$s\') AND %1$s.payable_minor + %3$s > %2$s)',
            $invoice,
            \sprintf(self::PAID, $invoice),
            \sprintf(self::CORRECTIONS, $invoice),
            DocumentKind::PaymentTaxDocument->value,
            DocumentKind::CorrectionDocument->value,
        );
    }

    public function isAuthoritative(): bool
    {
        return true;
    }
}
