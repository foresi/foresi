<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * The numbers of the start page, read with the rules of the lists: the month sums
 * of the list for the accountant, the unpaid and overdue tabs, and the documents
 * the accountant has not had yet.
 */
final readonly class InvoicingOverview
{
    public const int DEBTORS = 5;
    /** The last day of each age bucket of overdue; past the last is the third. */
    public const array AGES = [30, 60];
    /** The kinds a month of VAT is made of. */
    private const array TAX_KINDS = [DocumentKind::Invoice, DocumentKind::PaymentTaxDocument, DocumentKind::CorrectionDocument];

    public function __construct(
        private InvoiceStore $store,
        private Receivables $receivables,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Whether any document exists, the trash included: without one the start page shows nothing.
     */
    public function hasDocuments(): bool
    {
        return $this->receivables->hasDocuments();
    }

    public function summary(): InvoicingSummary
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $last = $now->modify('first day of last month');

        $zero = Money::ofMinor(0, Currency::of('CZK'));
        [$unpaid, $unpaidCount, $overdue, $overdueCount] = [$zero, 0, $zero, 0];
        $ages = [$zero, $zero, $zero];
        // Keyed by name, which PHP turns into an int when it is one: "42" stays a name.
        /** @var array<array-key, array{Money, int}> $owed */
        $owed = [];
        foreach ($this->receivables->open() as $receivable) {
            $unpaid = $unpaid->plus($receivable->remaining);
            ++$unpaidCount;
            [$amount, $count] = $owed[$receivable->customerName] ?? [$zero, 0];
            $owed[$receivable->customerName] = [$amount->plus($receivable->remaining), $count + 1];
            if ($receivable->daysOverdue > 0) {
                $overdue = $overdue->plus($receivable->remaining);
                ++$overdueCount;
                $age = $receivable->daysOverdue <= self::AGES[0] ? 0 : ($receivable->daysOverdue <= self::AGES[1] ? 1 : 2);
                $ages[$age] = $ages[$age]->plus($receivable->remaining);
            }
        }
        $debtors = array_map(static fn (int|string $name, array $owes): Debtor => new Debtor((string) $name, $owes[0], $owes[1]), array_keys($owed), $owed);
        usort($debtors, static fn (Debtor $a, Debtor $b): int => [$b->amount->minor(), $a->name] <=> [$a->amount->minor(), $b->name]);

        return new InvoicingSummary(
            $this->month($now->format('Y-m')),
            $this->month($last->format('Y-m')),
            $unpaid,
            $unpaidCount,
            $overdue,
            $overdueCount,
            $ages,
            \array_slice($debtors, 0, self::DEBTORS),
            $this->pending($last),
        );
    }

    private function month(string $month): MonthInvoiced
    {
        $zero = Money::ofMinor(0, Currency::of('CZK'));
        [$count, $base, $vat, $total] = [0, $zero, $zero, $zero];
        foreach (self::TAX_KINDS as $kind) {
            $sums = $this->store->sums(new InvoiceFilter(InvoiceFilter::ALL, '', InvoiceFilter::DEFAULT_SORT, 1, kind: $kind, month: $month));
            $count += $sums->count;
            $base = $base->plus($sums->base);
            $vat = $vat->plus($sums->vat);
            $total = $total->plus($sums->total);
        }

        return new MonthInvoiced($month, $count, $base, $vat, $total);
    }

    /**
     * @param \DateTimeImmutable $month its first day, at any time: the store compares days only
     *
     * @return list<PendingExport>
     */
    private function pending(\DateTimeImmutable $month): array
    {
        $count = \count($this->store->forAccountant($month, $month->modify('last day of this month'), true));

        return $count > 0 ? [new PendingExport($month->format('Y-m'), $count)] : [];
    }
}
