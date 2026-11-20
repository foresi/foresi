<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Dashboard;

use Foresi\Identity\Application\StaffCheck;
use Foresi\Invoicing\Application\Debtor;
use Foresi\Invoicing\Application\InvoiceFilter;
use Foresi\Invoicing\Application\InvoicingOverview;
use Foresi\Invoicing\Application\InvoicingSummary;
use Foresi\Invoicing\Application\MonthInvoiced;
use Foresi\Invoicing\Application\PendingExport;
use Foresi\Invoicing\Infrastructure\Form\AmountText;
use Foresi\Ui\Contract\Dashboard\DashboardSection;
use Foresi\Ui\Contract\Dashboard\DashboardSectionProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The invoicing overview on the start page, for staff of an organisation with documents.
 * Each number opens the filtered view of the list it counts.
 */
final readonly class InvoicingDashboard implements DashboardSectionProvider
{
    public function __construct(
        private StaffCheck $staff,
        private InvoicingOverview $overview,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private AmountText $amounts,
    ) {
    }

    public function sections(): iterable
    {
        if (!$this->staff->isStaff()) {
            return [];
        }
        if (!$this->overview->hasDocuments()) {
            return [];
        }
        $summary = $this->overview->summary();

        return [new DashboardSection('@Invoicing/dashboard.html.twig', [
            'kpis' => $this->kpis($summary),
            'facts' => $this->facts($summary),
            'debtors' => array_map(fn (Debtor $debtor): array => [
                'name' => $debtor->name,
                'amount' => $this->amounts->shown($debtor->amount),
                'count' => $this->translator->trans('dashboard.invoicing.documents', ['count' => $debtor->count]),
                'href' => $this->list(['tab' => InvoiceFilter::UNPAID, 'q' => $debtor->name]),
            ], $summary->debtors),
            'pending' => array_map(fn (PendingExport $pending): array => [
                'text' => $this->translator->trans('dashboard.invoicing.pending', [
                    'month' => $this->monthName($pending->month),
                    'count' => $pending->count,
                ]),
                'href' => $this->urls->generate('app_invoice_export', ['month' => $pending->month]),
            ], $summary->pending),
        ], 10)];
    }

    /**
     * @return list<array<string, string>>
     */
    private function kpis(InvoicingSummary $summary): array
    {
        $invoiced = fn (MonthInvoiced $month): array => [
            'label' => $this->translator->trans('dashboard.invoicing.invoiced', ['month' => $this->monthName($month->month)]),
            'value' => $this->amounts->shown($month->total),
            'note' => $this->translator->trans('dashboard.invoicing.base', ['amount' => $this->amounts->shown($month->base)]),
            'href' => $this->list(['month' => $month->month]),
        ];

        return [
            $invoiced($summary->thisMonth),
            $invoiced($summary->lastMonth),
            [
                'label' => $this->translator->trans('dashboard.invoicing.unpaid'),
                'value' => $this->amounts->shown($summary->unpaid),
                'note' => $this->translator->trans('dashboard.invoicing.documents', ['count' => $summary->unpaidCount]),
                'href' => $this->list(['tab' => InvoiceFilter::UNPAID]),
            ],
            [
                'label' => $this->translator->trans('dashboard.invoicing.overdue'),
                'value' => $this->amounts->shown($summary->overdue),
                'note' => $this->translator->trans('dashboard.invoicing.documents', ['count' => $summary->overdueCount]),
                'href' => $this->list(['tab' => InvoiceFilter::OVERDUE]),
            ],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function facts(InvoicingSummary $summary): array
    {
        $overdue = $this->list(['tab' => InvoiceFilter::OVERDUE]);
        [$young, $middle, $old] = $summary->overdueByAge;

        return [
            ['label' => $this->translator->trans('dashboard.invoicing.vat', ['month' => $this->monthName($summary->thisMonth->month)]), 'value' => $this->amounts->shown($summary->thisMonth->vat), 'href' => $this->list(['month' => $summary->thisMonth->month])],
            ['label' => $this->translator->trans('dashboard.invoicing.vat', ['month' => $this->monthName($summary->lastMonth->month)]), 'value' => $this->amounts->shown($summary->lastMonth->vat), 'href' => $this->list(['month' => $summary->lastMonth->month])],
            ['label' => $this->translator->trans('dashboard.invoicing.age_30'), 'value' => $this->amounts->shown($young), 'href' => $overdue],
            ['label' => $this->translator->trans('dashboard.invoicing.age_60'), 'value' => $this->amounts->shown($middle), 'href' => $overdue],
            ['label' => $this->translator->trans('dashboard.invoicing.age_over'), 'value' => $this->amounts->shown($old), 'href' => $overdue],
        ];
    }

    /**
     * @param array<string, string> $query
     */
    private function list(array $query): string
    {
        return $this->urls->generate('app_invoices', $query);
    }

    /**
     * The month as a sentence starts with it: "Září 2026".
     */
    private function monthName(string $month): string
    {
        $formatter = new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, 'LLLL y');
        $name = (string) $formatter->format(new \DateTimeImmutable($month.'-01', new \DateTimeZone('UTC')));

        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }
}
