<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Navigation;

use App\Identity\Application\StaffCheck;
use App\Invoicing\Application\InvoiceSends;
use App\Invoicing\Domain\SendStatus;
use App\Ui\Notice\ShellNotice;
use App\Ui\Notice\ShellNoticeProvider;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells staff on every page that sending needs them: an invoice whose newest send
 * failed, until a later one goes out or the invoice goes to the trash, and a send
 * still queued after 15 minutes, which most likely means no worker runs. Both
 * lead to the send log, filtered to what is wrong.
 */
final readonly class SendNotices implements ShellNoticeProvider
{
    public const int STUCK_AFTER_MINUTES = 15;

    public function __construct(
        private InvoiceSends $sends,
        private StaffCheck $staff,
        private ClockInterface $clock,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private RequestStack $requests,
    ) {
    }

    public function notices(): iterable
    {
        // The log is where the notices lead, so it does not repeat them.
        if (!$this->staff->isStaff() || 'app_invoice_sends' === $this->requests->getCurrentRequest()?->attributes->get('_route')) {
            return;
        }
        $failing = $this->sends->failing();
        if ($failing > 0) {
            yield new ShellNotice(
                'error',
                $this->translator->trans('invoice.sends.notice.failed', ['count' => $failing]),
                $this->translator->trans('invoice.sends.notice.link'),
                $this->urls->generate('app_invoice_sends', ['status' => SendStatus::Failed->value]),
            );
        }
        $before = \DateTimeImmutable::createFromInterface($this->clock->now())->modify(\sprintf('-%d minutes', self::STUCK_AFTER_MINUTES));
        $waiting = $this->sends->queuedBefore($before);
        if ($waiting > 0) {
            yield new ShellNotice(
                'warning',
                $this->translator->trans('invoice.sends.notice.stuck', ['count' => $waiting, 'minutes' => self::STUCK_AFTER_MINUTES]),
                $this->translator->trans('invoice.sends.notice.link'),
                $this->urls->generate('app_invoice_sends', ['status' => SendStatus::Queued->value]),
            );
        }
    }
}
