<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Navigation;

use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\MissingTaxDocument;
use App\Invoicing\Application\MissingTaxDocuments;
use App\Tenancy\Application\TenantClock;
use App\Ui\Notice\ShellNotice;
use App\Ui\Notice\ShellNoticeProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells staff on every page that paid advance invoices wait for a tax document,
 * with the soonest deadline, until each has one. Past a deadline it is an error.
 * iDoklad warns three days before the deadline; this tells from the payment on.
 */
final readonly class TaxDocumentNotices implements ShellNoticeProvider
{
    public function __construct(
        private MissingTaxDocuments $missing,
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
        $missing = $this->missing->on($this->tenantClock->today());
        if ([] === $missing) {
            return;
        }
        $late = array_any($missing, static fn (MissingTaxDocument $row): bool => $row->isLate());
        yield new ShellNotice(
            $late ? 'error' : 'warning',
            $this->translator->trans('advance.missing.notice', ['count' => \count($missing), 'late' => $late ? 'yes' : 'no', 'date' => $missing[0]->deadline->format($this->translator->trans('format.date'))]),
            $this->translator->trans('advance.missing.notice_link'),
            $this->urls->generate('app_advances_missing'),
        );
    }
}
