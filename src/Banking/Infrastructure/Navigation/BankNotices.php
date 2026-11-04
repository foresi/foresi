<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Navigation;

use App\Banking\Application\BankMovements;
use App\Banking\Application\BankScreen;
use App\Banking\Application\InboundMessages;
use App\Identity\Application\StaffCheck;
use App\Ui\Notice\ShellNotice;
use App\Ui\Notice\ShellNoticeProvider;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells staff on every page that bank mail needs them: messages that failed or
 * were rejected, until someone tries them again, records them anyway or dismisses
 * them, and messages the queue gave up on, still waiting after an hour; and
 * incoming payments no invoice was paired with. Both lead to the bank screen,
 * which does not repeat them. Without that screen there is nowhere to send
 * anyone, so nothing is shown.
 */
final readonly class BankNotices implements ShellNoticeProvider
{
    public const string ROUTE = 'app_bank_movements';

    public function __construct(
        private InboundMessages $messages,
        private BankMovements $movements,
        private StaffCheck $staff,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private RequestStack $requests,
        private ClockInterface $clock,
    ) {
    }

    public function notices(): iterable
    {
        if (!$this->staff->isStaff() || self::ROUTE === $this->requests->getCurrentRequest()?->attributes->get('_route')) {
            return;
        }
        $messages = $this->messages->needingAttention(BankScreen::stuckBefore($this->clock));
        if ($messages > 0 && null !== ($href = $this->href('messages'))) {
            yield new ShellNotice('error', $this->translator->trans('bank.inbox.notice.failed', ['count' => $messages]), $this->translator->trans('bank.inbox.notice.link'), $href);
        }
        $unmatched = $this->movements->unmatchedIncoming();
        if ($unmatched > 0 && null !== ($href = $this->href('unmatched'))) {
            yield new ShellNotice('info', $this->translator->trans('bank.inbox.notice.unmatched', ['count' => $unmatched]), $this->translator->trans('bank.inbox.notice.link'), $href);
        }
    }

    private function href(string $tab): ?string
    {
        try {
            return $this->urls->generate(self::ROUTE, ['tab' => $tab]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
