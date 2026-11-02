<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Party\Domain\LegalEntity;
use App\Tenancy\Application\TenantClock;

/**
 * Which invoices of a firm would be reminded tomorrow, so the owner sees it before
 * the first reminder leaves. The day is the tenant's, as the reminder run counts it.
 */
final readonly class ReminderPreview
{
    public const int SHOWN = 10;
    /** Candidates read before the open amounts thin them out to what is shown. */
    private const int READ = 100;

    public function __construct(
        private ReminderSettings $settings,
        private RemindableInvoices $remindable,
        private InvoiceBalances $balances,
        private InvoiceStore $store,
        private TenantClock $clock,
    ) {
    }

    /**
     * @return list<InvoiceRow> at most SHOWN, oldest due date first
     */
    public function tomorrow(LegalEntity $firm): array
    {
        $settings = $this->settings->for($firm);
        $tomorrow = $this->today()->modify('+1 day');
        $due = $this->remindable->due($firm, $settings, $tomorrow, self::READ);
        if ([] === $due) {
            return [];
        }
        $outstanding = $this->balances->outstanding(array_map(static fn (RemindableInvoice $invoice): string => $invoice->invoiceId, $due));

        $rows = [];
        foreach ($due as $candidate) {
            if (!$settings->isWorthReminding($outstanding[$candidate->invoiceId])) {
                continue;
            }
            $rows[] = $this->row($candidate->invoiceId, $tomorrow);
            if (self::SHOWN === \count($rows)) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Today of the tenant, at its start, in the tenant's zone.
     */
    public function today(): \DateTimeImmutable
    {
        return $this->clock->today();
    }

    private function row(string $id, \DateTimeImmutable $on): InvoiceRow
    {
        $invoice = $this->store->get($id);

        return new InvoiceRow(
            $invoice->id(),
            $invoice->number(),
            $invoice->firm()->name(),
            $invoice->customer()->name,
            $invoice->issueDate(),
            $invoice->dueDate(),
            $invoice->payable(),
            $invoice->isDelivered(),
            $invoice->dueDate()->format('Y-m-d') < $on->format('Y-m-d'),
            $invoice->isTrashed(),
        );
    }
}
