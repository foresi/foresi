<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Navigation;

use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\MissingTaxDocuments;
use App\Invoicing\Application\UncorrectedTaxDocuments;
use App\Tenancy\Application\TenantClock;
use App\Ui\Notice\ShellNotice;
use App\Ui\Notice\ShellNoticeProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells staff on every page that paid advance invoices wait for a tax document, or
 * delivered tax documents for a corrective one, with the soonest deadline, until
 * each has one; the link leads to the list of that soonest one. Past a deadline it
 * is an error.
 * iDoklad warns three days before the deadline; this tells from the payment on.
 */
final readonly class TaxDocumentNotices implements ShellNoticeProvider
{
    public function __construct(
        private MissingTaxDocuments $missing,
        private UncorrectedTaxDocuments $uncorrected,
        private TenantClock $tenantClock,
        private StaffCheck $staff,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function notices(): iterable
    {
        if (!$this->staff->isStaff()) {
            return;
        }
        $today = $this->tenantClock->today();
        $deadlines = [];
        foreach ($this->missing->on($today) as $row) {
            $deadlines[] = ['deadline' => $row->deadline, 'late' => $row->isLate(), 'route' => 'app_advances_missing'];
        }
        $missing = \count($deadlines);
        foreach ($this->uncorrected->on($today) as $row) {
            $deadlines[] = ['deadline' => $row->deadline, 'late' => $row->isLate(), 'route' => 'app_tax_documents_uncorrected'];
        }
        if ([] === $deadlines) {
            return;
        }
        usort($deadlines, static fn (array $a, array $b): int => $a['deadline'] <=> $b['deadline']);
        $late = array_any($deadlines, static fn (array $row): bool => $row['late']);
        $key = match (true) {
            \count($deadlines) === $missing => 'advance.missing.notice',
            0 === $missing => 'tax_document.uncorrected.notice',
            default => 'tax_document.waiting_notice',
        };
        yield new ShellNotice(
            $late ? 'error' : 'warning',
            $this->translator->trans($key, ['count' => \count($deadlines), 'late' => $late ? 'yes' : 'no', 'date' => $deadlines[0]['deadline']->format($this->translator->trans('format.date'))]),
            $this->translator->trans('advance.missing.notice_link'),
            $this->urls->generate($deadlines[0]['route']),
        );
    }
}
