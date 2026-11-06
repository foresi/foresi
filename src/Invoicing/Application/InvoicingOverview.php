<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;
use App\Party\Application\LegalEntities;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
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
        private LegalEntities $firms,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, string> name by id of every firm with a document
     */
    public function firms(): array
    {
        return $this->receivables->firms();
    }

    /**
     * @param string|null $firmId a firm without a document covers all, as the list does
     */
    public function of(?string $firmId): InvoicingSummary
    {
        $firms = $this->receivables->firms();
        $firmId = null !== $firmId && isset($firms[$firmId]) ? $firmId : null;
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $last = $now->modify('first day of last month');

        $zero = Money::ofMinor(0, Currency::CZK);
        [$unpaid, $unpaidCount, $overdue, $overdueCount] = [$zero, 0, $zero, 0];
        $ages = [$zero, $zero, $zero];
        // Keyed by name, which PHP turns into an int when it is one: "42" stays a name.
        /** @var array<array-key, array{Money, int}> $owed */
        $owed = [];
        foreach ($this->receivables->open($firmId) as $receivable) {
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
            $firmId,
            $this->month($firmId, $now->format('Y-m')),
            $this->month($firmId, $last->format('Y-m')),
            $unpaid,
            $unpaidCount,
            $overdue,
            $overdueCount,
            $ages,
            \array_slice($debtors, 0, self::DEBTORS),
            $this->pending(null === $firmId ? $firms : [$firmId => $firms[$firmId]], $last),
        );
    }

    private function month(?string $firmId, string $month): MonthInvoiced
    {
        $zero = Money::ofMinor(0, Currency::CZK);
        [$count, $base, $vat, $total] = [0, $zero, $zero, $zero];
        foreach (self::TAX_KINDS as $kind) {
            $sums = $this->store->sums(new InvoiceFilter($firmId, InvoiceFilter::ALL, '', InvoiceFilter::DEFAULT_SORT, 1, kind: $kind, month: $month));
            $count += $sums->count;
            $base = $base->plus($sums->base);
            $vat = $vat->plus($sums->vat);
            $total = $total->plus($sums->total);
        }

        return new MonthInvoiced($month, $count, $base, $vat, $total);
    }

    /**
     * @param array<string, string> $firms name by id
     *
     * @return list<PendingExport>
     */
    private function pending(array $firms, \DateTimeImmutable $month): array
    {
        $from = $month->setTime(0, 0);
        $to = $from->modify('last day of this month');
        $pending = [];
        foreach ($firms as $id => $name) {
            $count = \count($this->store->forAccountant($this->firms->get($id), $from, $to, true));
            if ($count > 0) {
                $pending[] = new PendingExport($id, $name, $from->format('Y-m'), $count);
            }
        }

        return $pending;
    }
}
