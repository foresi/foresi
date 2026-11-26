<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Navigation;

use Foresi\Banking\Application\BankMovements;
use Foresi\Banking\Application\BankScreen;
use Foresi\Banking\Application\InboundMessages;
use Foresi\Banking\Application\ReceivedStatements;
use Foresi\Banking\Application\StatementChain;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Ui\Contract\Notice\ShellNotice;
use Foresi\Ui\Contract\Notice\ShellNoticeProvider;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells staff on every page that bank mail needs them: messages that failed or
 * were rejected, until someone tries them again, records them anyway or dismisses
 * them, and messages the queue gave up on, still waiting after an hour; and
 * incoming payments no invoice was paired with; and bank statements that do not
 * follow on, on the Statements tab. All lead to the bank screen, which does not
 * repeat them. Without that screen there is nowhere to send
 * anyone, so nothing is shown.
 */
final readonly class BankNotices implements ShellNoticeProvider
{
    public const string ROUTE = 'app_bank_movements';
    public const string STATEMENTS_ROUTE = 'app_bank_statements';

    public function __construct(
        private InboundMessages $messages,
        private BankMovements $movements,
        private StaffCheck $staff,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private RequestStack $requests,
        private ClockInterface $clock,
        private ReceivedStatements $statements,
    ) {
    }

    public function notices(): iterable
    {
        if (!$this->staff->isStaff() || \in_array($this->requests->getCurrentRequest()?->attributes->get('_route'), [self::ROUTE, self::STATEMENTS_ROUTE], true)) {
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
        $accounts = StatementChain::accountsWithGaps($this->statements->all());
        if ($accounts > 0 && null !== ($href = $this->href(null, self::STATEMENTS_ROUTE))) {
            yield new ShellNotice('warning', $this->translator->trans('bank.statement.notice', ['count' => $accounts]), $this->translator->trans('bank.inbox.notice.link'), $href);
        }
    }

    private function href(?string $view, string $route = self::ROUTE): ?string
    {
        try {
            return $this->urls->generate($route, null === $view ? [] : ['view' => $view]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
