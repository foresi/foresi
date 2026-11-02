<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Navigation;

use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\IssueRecurringInvoices;
use App\Invoicing\Application\RecurringInvoices;
use App\Tenancy\Application\Schedule\ScheduledJobRuns;
use App\Tenancy\Application\TenantClock;
use App\Ui\Notice\ShellNotice;
use App\Ui\Notice\ShellNoticeProvider;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells staff on every page that recurring invoices need them: templates whose
 * last run was refused, until a run goes clean or the template ends; invoices a
 * template issued that wait to be sent, held for review or refused for sending;
 * and a day whose run has not finished by LATE_HOUR in the tenant's zone while
 * templates are due, which most likely means no worker or scheduler runs.
 */
final readonly class RecurringNotices implements ShellNoticeProvider
{
    /** The local hour by which the day's run, started at 05:00, has finished. */
    public const int LATE_HOUR = 7;

    public function __construct(
        private RecurringInvoices $templates,
        private ScheduledJobRuns $runs,
        private TenantClock $tenantClock,
        private StaffCheck $staff,
        private ClockInterface $clock,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function notices(): iterable
    {
        if (!$this->staff->isStaff()) {
            return;
        }
        $failing = $this->templates->failing();
        if ($failing > 0) {
            yield new ShellNotice('error', $this->translator->trans('recurring.notice.failing', ['count' => $failing]), $this->translator->trans('recurring.notice.link'), $this->templatesUrl(['tab' => 'failing']));
        }
        if ($this->runIsLate()) {
            yield new ShellNotice('error', $this->translator->trans('recurring.notice.unfinished', ['hour' => self::LATE_HOUR]), $this->translator->trans('recurring.notice.link'), $this->templatesUrl([]));
        }
        $inReview = $this->templates->inReview();
        if ($inReview > 0) {
            yield new ShellNotice('warning', $this->translator->trans('recurring.notice.review', ['count' => $inReview]), $this->translator->trans('recurring.notice.link'), $this->urls->generate('app_invoices', ['toSend' => 1]));
        }
    }

    private function runIsLate(): bool
    {
        $local = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone($this->tenantClock->timezone());
        if ((int) $local->format('G') < self::LATE_HOUR) {
            return false;
        }
        $today = $local->format('Y-m-d');
        $last = $this->runs->lastFinished(IssueRecurringInvoices::NAME);
        if (null !== $last && $last >= $today) {
            return false;
        }

        return [] !== $this->templates->due(new \DateTimeImmutable($today));
    }

    /**
     * The list of templates, or the list of invoices where no such route is installed.
     *
     * @param array<string, string> $query
     */
    private function templatesUrl(array $query): string
    {
        try {
            return $this->urls->generate('app_recurring', $query);
        } catch (RouteNotFoundException) {
            return $this->urls->generate('app_invoices');
        }
    }
}
