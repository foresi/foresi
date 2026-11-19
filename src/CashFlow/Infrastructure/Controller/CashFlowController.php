<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Controller;

use Foresi\CashFlow\Application\BalanceWrites;
use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowRefused;
use Foresi\CashFlow\Application\Forecast;
use Foresi\CashFlow\Application\RecordCheckpointInput;
use Foresi\CashFlow\Application\SetMinimumBalanceInput;
use Foresi\CashFlow\Contract\FlowCategory;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Infrastructure\Screen\ForecastScreen;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Infrastructure\Http\TypedNumber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Cash flow page under Money: how much money the firm will have, the
 * figures Float and QuickBooks lead with, and the periods as a table whose
 * cells open their items in the sheet. Entering a balance and the minimum
 * balance are small dialogs over it (docs/design/overlays.md). Only the
 * owners and members of the organisation see it.
 */
final class CashFlowController extends AbstractController
{
    public const string ROUTE = 'app_cash_flow';
    public const string BALANCE_TOKEN = 'cash_flow_balance';
    public const string MINIMUM_TOKEN = 'cash_flow_minimum';

    public function __construct(
        private readonly ForecastScreen $screen,
        private readonly BalanceWrites $balances,
        private readonly CashFlowAccess $access,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/cash-flow', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $view = ForecastScreen::WEEKS === $request->query->getString('view') ? ForecastScreen::WEEKS : ForecastScreen::MONTHS;
        $forecast = $this->forecastOrNull($view);
        if (!$forecast instanceof Forecast) {
            return $this->render('@CashFlow/index.html.twig', ['noFirm' => true]);
        }

        return $this->render('@CashFlow/index.html.twig', [
            'noFirm' => false,
            'noAccount' => [] === $forecast->accounts,
            'empty' => $this->screen->isEmpty($forecast),
            'kpis' => $this->screen->kpis($forecast),
            'alert' => $this->screen->alert($forecast),
            'table' => $this->screen->table($forecast),
            'chart' => $this->screen->chart($forecast),
            'views' => $this->screen->views($view),
            'minimumSet' => $forecast->minimum instanceof Money,
        ]);
    }

    #[Route('/cash-flow/items', name: 'app_cash_flow_items', methods: ['GET'])]
    public function items(Request $request): Response
    {
        $key = $request->query->getString('period');
        $forecast = $this->forecastOrNull(ForecastScreen::viewOf($key)) ?? throw new NotFoundHttpException();
        $direction = FlowDirection::tryFrom($request->query->getString('direction')) ?? throw new NotFoundHttpException();
        $category = $request->query->has('category') ? (FlowCategory::tryFrom($request->query->getString('category')) ?? throw new NotFoundHttpException()) : null;
        $period = $this->screen->period($forecast, $key) ?? throw new NotFoundHttpException();

        return $this->render('@CashFlow/items.html.twig', $this->screen->items($period, $direction, $category));
    }

    #[Route('/cash-flow/balance', name: 'app_cash_flow_balance', methods: ['GET', 'POST'])]
    public function balance(Request $request): Response
    {
        $accounts = $this->accounts();
        if ([] === $accounts) {
            throw new NotFoundHttpException();
        }
        $values = [
            'account' => $request->query->getString('account', $accounts[0]->id()),
            'on' => $this->clock->today()->format('Y-m-d'),
            'amount' => '',
        ];
        if (!$request->isMethod('POST')) {
            return $this->balanceForm($accounts, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request, self::BALANCE_TOKEN);
        $values = [
            'account' => $request->request->getString('account'),
            'on' => trim($request->request->getString('on')),
            'amount' => trim($request->request->getString('amount')),
        ];
        $account = array_find($accounts, static fn (FirmBankAccount $candidate): bool => $candidate->id() === $values['account']);
        if (!$account instanceof FirmBankAccount) {
            return $this->balanceForm($accounts, $values, ['account' => 'cash_flow.balance.error.account'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ('' === $values['amount']) {
            return $this->balanceForm($accounts, $values, ['amount' => 'cash_flow.balance.error.amount_required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->balances->checkpoint(new RecordCheckpointInput($account->id(), $values['on'], TypedNumber::decimal($values['amount'], $request->getLocale())));
        } catch (CashFlowRefused $refused) {
            $this->denyIfForbidden($refused);

            return $this->balanceForm($accounts, $values, [$this->balanceField($refused) => 'cash_flow.balance.error.'.$refused->reason()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('cash_flow.balance.flash', ['account' => $this->screen->accountName($account), 'day' => $this->screen->day(new \DateTimeImmutable($values['on'], $this->clock->timezone()))]));

        return $this->redirectToRoute(self::ROUTE);
    }

    #[Route('/cash-flow/minimum-balance', name: 'app_cash_flow_minimum', methods: ['GET', 'POST'])]
    public function minimum(Request $request): Response
    {
        $forecast = $this->forecastOrNull() ?? throw new NotFoundHttpException();
        $current = $forecast->minimum;
        $values = ['amount' => $current instanceof Money ? $this->plain($current, $request->getLocale()) : ''];
        if (!$request->isMethod('POST')) {
            return $this->minimumForm($values, [], $current instanceof Money, Response::HTTP_OK);
        }

        $this->checkToken($request, self::MINIMUM_TOKEN);
        $clear = '1' === $request->request->getString('clear');
        $values = ['amount' => trim($request->request->getString('amount'))];
        if (!$clear && '' === $values['amount']) {
            return $this->minimumForm($values, ['amount' => 'cash_flow.minimum.error.amount_required'], $current instanceof Money, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->balances->minimum(new SetMinimumBalanceInput($clear ? null : TypedNumber::decimal($values['amount'], $request->getLocale())));
        } catch (CashFlowRefused $refused) {
            $this->denyIfForbidden($refused);

            return $this->minimumForm($values, ['amount' => 'cash_flow.minimum.error.'.$refused->reason()], $current instanceof Money, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans($clear ? 'cash_flow.minimum.flash_cleared' : 'cash_flow.minimum.flash_saved'));

        return $this->redirectToRoute(self::ROUTE);
    }

    /**
     * The forecast of a view, or null before the first-run setup gave the instance its firm.
     */
    private function forecastOrNull(string $view = ForecastScreen::MONTHS): ?Forecast
    {
        try {
            return $this->screen->forecast($view);
        } catch (CashFlowRefused $refused) {
            $this->denyIfForbidden($refused);

            return null;
        }
    }

    /**
     * @return list<FirmBankAccount>
     */
    private function accounts(): array
    {
        try {
            $this->access->denyUnlessStaff();

            return $this->access->accountsOf($this->access->firm());
        } catch (CashFlowRefused $refused) {
            $this->denyIfForbidden($refused);

            return [];
        }
    }

    private function denyIfForbidden(CashFlowRefused $refused): void
    {
        if (RefusalKind::Forbidden === $refused->kind()) {
            throw new AccessDeniedHttpException();
        }
    }

    private function balanceField(CashFlowRefused $refused): string
    {
        return match ($refused->field()) {
            'on' => 'on',
            'amount' => 'amount',
            default => 'account',
        };
    }

    /**
     * @param list<FirmBankAccount> $accounts
     * @param array<string, string> $values
     * @param array<string, string> $errors   translation keys by field
     */
    private function balanceForm(array $accounts, array $values, array $errors, int $status): Response
    {
        $options = [];
        foreach ($accounts as $account) {
            $options[$account->id()] = $this->screen->accountName($account).' ('.$account->currency()->value.')';
        }

        return $this->render('@CashFlow/balance.html.twig', [
            'values' => $values,
            'errors' => array_map(fn (string $key): string => $this->translator->trans($key), $errors),
            'accounts' => $options,
            'today' => $this->clock->today()->format('Y-m-d'),
        ], new Response(status: $status));
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors translation keys by field
     */
    private function minimumForm(array $values, array $errors, bool $set, int $status): Response
    {
        return $this->render('@CashFlow/minimum.html.twig', [
            'values' => $values,
            'errors' => array_map(fn (string $key): string => $this->translator->trans($key), $errors),
            'set' => $set,
        ], new Response(status: $status));
    }

    /**
     * For a field, without grouping: "50000,00" in Czech.
     */
    private function plain(Money $money, string $locale): string
    {
        $symbol = new \NumberFormatter($locale, \NumberFormatter::DECIMAL)->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);

        return \sprintf('%s%d%s%02d', $money->isNegative() ? '-' : '', intdiv(abs($money->minor()), 100), $symbol, abs($money->minor()) % 100);
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
