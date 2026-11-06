<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Dashboard;

use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\Debtor;
use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoicingOverview;
use App\Invoicing\Application\InvoicingSummary;
use App\Invoicing\Application\MonthInvoiced;
use App\Invoicing\Application\PendingExport;
use App\Invoicing\Infrastructure\Form\AmountText;
use App\Ui\Dashboard\DashboardSection;
use App\Ui\Dashboard\DashboardSectionProvider;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The invoicing overview on the start page, for staff of a tenant with documents.
 * The firm comes from the query, as on the list, whose filtered views each number opens.
 */
final readonly class InvoicingDashboard implements DashboardSectionProvider
{
    public function __construct(
        private StaffCheck $staff,
        private InvoicingOverview $overview,
        private RequestStack $requests,
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
        $firms = $this->overview->firms();
        if ([] === $firms) {
            return [];
        }
        $asked = $this->requests->getCurrentRequest()?->query->getString('firm') ?? '';
        $summary = $this->overview->of('' === $asked ? null : $asked);

        return [new DashboardSection('invoicing/dashboard.html.twig', [
            'firms' => \count($firms) > 1 ? ['' => $this->translator->trans('invoice.list.all_firms')] + $firms : [],
            'firm' => $summary->firmId ?? '',
            'kpis' => $this->kpis($summary),
            'facts' => $this->facts($summary),
            'debtors' => array_map(fn (Debtor $debtor): array => [
                'name' => $debtor->name,
                'amount' => $this->amounts->shown($debtor->amount),
                'count' => $this->translator->trans('dashboard.invoicing.documents', ['count' => $debtor->count]),
                'href' => $this->list($summary, ['tab' => InvoiceFilter::UNPAID, 'q' => $debtor->name]),
            ], $summary->debtors),
            'pending' => array_map(fn (PendingExport $pending): array => [
                'text' => $this->translator->trans(\count($firms) > 1 && null === $summary->firmId ? 'dashboard.invoicing.pending_firm' : 'dashboard.invoicing.pending', [
                    'firm' => $pending->firmName,
                    'month' => $this->monthName($pending->month),
                    'count' => $pending->count,
                ]),
                'href' => $this->urls->generate('app_invoice_export', ['firm' => $pending->firmId, 'month' => $pending->month]),
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
            'href' => $this->list($summary, ['month' => $month->month]),
        ];

        return [
            $invoiced($summary->thisMonth),
            $invoiced($summary->lastMonth),
            [
                'label' => $this->translator->trans('dashboard.invoicing.unpaid'),
                'value' => $this->amounts->shown($summary->unpaid),
                'note' => $this->translator->trans('dashboard.invoicing.documents', ['count' => $summary->unpaidCount]),
                'href' => $this->list($summary, ['tab' => InvoiceFilter::UNPAID]),
            ],
            [
                'label' => $this->translator->trans('dashboard.invoicing.overdue'),
                'value' => $this->amounts->shown($summary->overdue),
                'note' => $this->translator->trans('dashboard.invoicing.documents', ['count' => $summary->overdueCount]),
                'href' => $this->list($summary, ['tab' => InvoiceFilter::OVERDUE]),
            ],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function facts(InvoicingSummary $summary): array
    {
        $overdue = $this->list($summary, ['tab' => InvoiceFilter::OVERDUE]);
        [$young, $middle, $old] = $summary->overdueByAge;

        return [
            ['label' => $this->translator->trans('dashboard.invoicing.vat', ['month' => $this->monthName($summary->thisMonth->month)]), 'value' => $this->amounts->shown($summary->thisMonth->vat), 'href' => $this->list($summary, ['month' => $summary->thisMonth->month])],
            ['label' => $this->translator->trans('dashboard.invoicing.vat', ['month' => $this->monthName($summary->lastMonth->month)]), 'value' => $this->amounts->shown($summary->lastMonth->vat), 'href' => $this->list($summary, ['month' => $summary->lastMonth->month])],
            ['label' => $this->translator->trans('dashboard.invoicing.age_30'), 'value' => $this->amounts->shown($young), 'href' => $overdue],
            ['label' => $this->translator->trans('dashboard.invoicing.age_60'), 'value' => $this->amounts->shown($middle), 'href' => $overdue],
            ['label' => $this->translator->trans('dashboard.invoicing.age_over'), 'value' => $this->amounts->shown($old), 'href' => $overdue],
        ];
    }

    /**
     * @param array<string, string> $query
     */
    private function list(InvoicingSummary $summary, array $query): string
    {
        return $this->urls->generate('app_invoices', null === $summary->firmId ? $query : ['firm' => $summary->firmId] + $query);
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
