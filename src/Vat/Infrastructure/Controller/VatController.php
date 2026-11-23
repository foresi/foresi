<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Controller;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Infrastructure\Http\TypedNumber;
use Foresi\Vat\Application\AssignPaymentInput;
use Foresi\Vat\Application\ClearReturnAmountInput;
use Foresi\Vat\Application\PeriodDetail;
use Foresi\Vat\Application\PeriodList;
use Foresi\Vat\Application\PeriodWrites;
use Foresi\Vat\Application\ReopenPeriodInput;
use Foresi\Vat\Application\ResetPaymentInput;
use Foresi\Vat\Application\SetReturnAmountInput;
use Foresi\Vat\Application\SettlePeriodInput;
use Foresi\Vat\Application\SetVatSettingsInput;
use Foresi\Vat\Application\VatPeriods;
use Foresi\Vat\Application\VatRefused;
use Foresi\Vat\Application\VatSettingsReads;
use Foresi\Vat\Application\VatSettingsView;
use Foresi\Vat\Application\VatSettingsWrites;
use Foresi\Vat\Infrastructure\Screen\VatScreen;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The page Money → VAT: the periods of a year as tabs (last, this and next),
 * one row each with its amounts and state, the warnings and the unassigned
 * payments above them, and one period in the side sheet with its breakdown,
 * documents, steps and payments (docs/design/overlays.md). The return amount,
 * the settled mark, a payment's period and where the firm pays its VAT are
 * small forms in dialogs. The owners and the members work with it; a guest is
 * refused with 403.
 */
final class VatController extends AbstractController
{
    public const string ROUTE = 'app_vat';
    public const string TOKEN = 'vat';

    /** A period key in a URL, "2026-11" or "2026-Q4"; the module refuses one it does not have. */
    private const string KEY = '[0-9A-Za-z-]{1,10}';

    /** A bank movement's subject id in a URL. */
    private const string MOVEMENT = '[^/]{1,64}';

    /** The posted value of "not a VAT payment" in the period select of a payment. */
    public const string NO_PERIOD = 'none';

    /** Whether the amount of a return is to pay or to get back. */
    public const string PAYABLE = 'payable';
    public const string REFUND = 'refund';

    public function __construct(
        private readonly VatPeriods $periods,
        private readonly PeriodWrites $writes,
        private readonly VatSettingsReads $settingsReads,
        private readonly VatSettingsWrites $settingsWrites,
        private readonly VatScreen $screen,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/vat', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $thisYear = (int) $this->clock->today()->format('Y');
        $year = $request->query->getString('year', (string) $thisYear);
        if (1 !== preg_match('/^[0-9]{4}\z/', $year)) {
            throw new NotFoundHttpException();
        }
        $year = (int) $year;
        $list = $this->refusedAsHttp(fn (): PeriodList => $this->periods->list(\sprintf('%04d-01', $year), \sprintf('%04d-12', $year)));

        return $this->render('@Vat/index.html.twig', [
            'year' => $year,
            'tabs' => $this->screen->tabs($year, $thisYear),
            'reason' => $list->reason,
            'rows' => $this->screen->rows($list),
            'warnings' => $this->screen->warnings($list),
            'duplicates' => $this->screen->duplicates($list),
            'unassigned' => $this->screen->unassigned($list),
            'footer' => $this->screen->footer($list),
        ]);
    }

    /**
     * One period in the side sheet; the same URL is a page.
     */
    #[Route('/vat/periods/{key}', name: self::ROUTE.'_period', requirements: ['key' => self::KEY], methods: ['GET'])]
    public function period(string $key): Response
    {
        $detail = $this->detail($key);

        return $this->render('@Vat/period.html.twig', [
            'key' => $detail->period->period->key,
            'year' => (int) $detail->period->period->start->format('Y'),
            'period' => $this->screen->detail($detail),
        ]);
    }

    /**
     * The amount of the filed return, which replaces the estimate as what
     * the period expects: a small form in a dialog over the sheet.
     */
    #[Route('/vat/periods/{key}/return-amount', name: self::ROUTE.'_return_amount', requirements: ['key' => self::KEY], methods: ['GET', 'POST'])]
    public function returnAmount(Request $request, string $key): Response
    {
        $detail = $this->detail($key);
        $key = $detail->period->period->key;
        $values = ['amount' => '', 'direction' => $detail->period->expected->isNegative() ? self::REFUND : self::PAYABLE];
        if (!$request->isMethod('POST')) {
            return $this->returnAmountForm($detail, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request);
        $values = ['amount' => trim($request->request->getString('amount')), 'direction' => self::REFUND === $request->request->getString('direction') ? self::REFUND : self::PAYABLE];
        $typed = TypedNumber::decimal($values['amount'], $request->getLocale());
        // The person types the amount as the return states it; a refund is negative in the module.
        $amount = self::REFUND === $values['direction'] && '' !== $typed && !str_starts_with($typed, '-') ? '-'.$typed : $typed;
        try {
            $this->writes->setReturnAmount(new SetReturnAmountInput($key, $amount));
        } catch (VatRefused $refused) {
            // Who may work with the VAT was asked when the form was read above.
            return $this->returnAmountForm($detail, $values, ['amount' => 'vat.page.error.'.$refused->reason()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('vat.page.flash.return_amount'));

        return $this->redirectToRoute(self::ROUTE.'_period', ['key' => $key]);
    }

    #[Route('/vat/periods/{key}/return-amount/clear', name: self::ROUTE.'_return_amount_clear', requirements: ['key' => self::KEY], methods: ['POST'])]
    public function clearReturnAmount(Request $request, string $key): RedirectResponse
    {
        $this->checkToken($request);
        $detail = $this->refusedAsHttp(fn (): PeriodDetail => $this->writes->clearReturnAmount(new ClearReturnAmountInput($key)));
        $this->addFlash('success', $this->translator->trans('vat.page.flash.return_amount_cleared'));

        return $this->redirectToRoute(self::ROUTE.'_period', ['key' => $detail->period->period->key]);
    }

    /**
     * Marks a period settled without a bank payment, such as one paid from
     * another account, with an optional note.
     */
    #[Route('/vat/periods/{key}/settle', name: self::ROUTE.'_settle', requirements: ['key' => self::KEY], methods: ['GET', 'POST'])]
    public function settle(Request $request, string $key): Response
    {
        $detail = $this->detail($key);
        $key = $detail->period->period->key;
        $values = ['note' => ''];
        if (!$request->isMethod('POST')) {
            return $this->settleForm($detail, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request);
        $values = ['note' => trim($request->request->getString('note'))];
        try {
            $this->writes->settle(new SettlePeriodInput($key, $values['note']));
        } catch (VatRefused $refused) {
            // Who may work with the VAT was asked when the form was read above.
            return $this->settleForm($detail, $values, ['note' => 'vat.page.error.'.$refused->reason()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('vat.page.flash.settled'));

        return $this->redirectToRoute(self::ROUTE.'_period', ['key' => $key]);
    }

    #[Route('/vat/periods/{key}/reopen', name: self::ROUTE.'_reopen', requirements: ['key' => self::KEY], methods: ['POST'])]
    public function reopen(Request $request, string $key): RedirectResponse
    {
        $this->checkToken($request);
        $detail = $this->refusedAsHttp(fn (): PeriodDetail => $this->writes->reopen(new ReopenPeriodInput($key)));
        $this->addFlash('success', $this->translator->trans('vat.page.flash.reopened'));

        return $this->redirectToRoute(self::ROUTE.'_period', ['key' => $detail->period->period->key]);
    }

    /**
     * Places a bank movement in a period of the person's choice, or says it
     * is no VAT payment: a small form in a dialog.
     */
    #[Route('/vat/payments/{movement}/move', name: self::ROUTE.'_payment_move', requirements: ['movement' => self::MOVEMENT], methods: ['GET', 'POST'])]
    public function movePayment(Request $request, string $movement): Response
    {
        $list = $this->refusedAsHttp(fn (): PeriodList => $this->periods->list(null, null));
        $back = $this->back($request);
        $values = ['period' => $back ?? ''];
        if (!$request->isMethod('POST')) {
            return $this->moveForm($movement, $list, $back, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request);
        $values = ['period' => $request->request->getString('period')];
        try {
            $this->writes->assign(new AssignPaymentInput($movement, self::NO_PERIOD === $values['period'] ? null : $values['period']));
        } catch (VatRefused $refused) {
            // Who may work with the VAT was asked when the form was read above.
            return $this->moveForm($movement, $list, $back, $values, ['period' => 'vat.page.error.'.$refused->reason()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('vat.page.flash.payment_moved'));

        return $this->backTo($back);
    }

    /**
     * Takes a bank movement off its period: it is no VAT payment.
     */
    #[Route('/vat/payments/{movement}/unpair', name: self::ROUTE.'_payment_unpair', requirements: ['movement' => self::MOVEMENT], methods: ['POST'])]
    public function unpairPayment(Request $request, string $movement): RedirectResponse
    {
        $this->checkToken($request);
        $this->refusedAsHttp(fn (): mixed => $this->writes->assign(new AssignPaymentInput($movement, null)));
        $this->addFlash('success', $this->translator->trans('vat.page.flash.payment_unpaired'));

        return $this->backTo($this->back($request));
    }

    /**
     * Gives a bank movement back to the automatic matching.
     */
    #[Route('/vat/payments/{movement}/reset', name: self::ROUTE.'_payment_reset', requirements: ['movement' => self::MOVEMENT], methods: ['POST'])]
    public function resetPayment(Request $request, string $movement): RedirectResponse
    {
        $this->checkToken($request);
        $this->refusedAsHttp(fn (): mixed => $this->writes->reset(new ResetPaymentInput($movement)));
        $this->addFlash('success', $this->translator->trans('vat.page.flash.payment_reset'));

        return $this->backTo($this->back($request));
    }

    /**
     * Where the firm pays its VAT: the account of its tax office and the
     * variable symbol, with what the country suggests. A dialog over the page.
     */
    #[Route('/vat/settings', name: self::ROUTE.'_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $current = $this->refusedAsHttp($this->settingsReads->current(...));
        $values = ['officeAccount' => $current->officeAccount ?? '', 'variableSymbol' => $current->variableSymbol ?? ''];
        if (!$request->isMethod('POST')) {
            return $this->settingsForm($current, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request);
        $values = ['officeAccount' => trim($request->request->getString('officeAccount')), 'variableSymbol' => trim($request->request->getString('variableSymbol'))];
        try {
            $this->settingsWrites->set(new SetVatSettingsInput($values['officeAccount'], $values['variableSymbol']));
        } catch (VatRefused $refused) {
            // Who may work with the VAT was asked when the form was read above.
            return $this->settingsForm($current, $values, [$refused->field() ?? 'officeAccount' => 'vat.page.error.'.$refused->reason()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('vat.page.flash.settings'));

        return $this->redirectToRoute(self::ROUTE);
    }

    private function detail(string $key): PeriodDetail
    {
        return $this->refusedAsHttp(fn (): PeriodDetail => $this->periods->get($key));
    }

    /**
     * The answer of a use case, its refusal as the HTTP error it means: 403
     * for one who may not work with the VAT, 404 for anything else.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function refusedAsHttp(callable $call): mixed
    {
        try {
            return $call();
        } catch (VatRefused $refused) {
            $this->denyIfForbidden($refused);

            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
    }

    /**
     * The period whose sheet a payment action came from, or null for the page.
     */
    private function back(Request $request): ?string
    {
        $back = $request->isMethod('POST') ? $request->request->getString('back') : $request->query->getString('back');

        return 1 === preg_match('/^[0-9]{4}-(?:[0-9]{2}|Q[1-4])\z/', $back) ? $back : null;
    }

    private function backTo(?string $back): RedirectResponse
    {
        return null === $back ? $this->redirectToRoute(self::ROUTE) : $this->redirectToRoute(self::ROUTE.'_period', ['key' => $back]);
    }

    /**
     * @param array{amount: string, direction: string} $values
     * @param array<string, string>                    $errors
     */
    private function returnAmountForm(PeriodDetail $detail, array $values, array $errors, int $status): Response
    {
        return $this->render('@Vat/return_amount.html.twig', [
            'key' => $detail->period->period->key,
            'title' => $this->screen->periodLabel($detail->period->period),
            'estimate' => $this->screen->row($detail->period)['result'],
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: $status));
    }

    /**
     * @param array{note: string}   $values
     * @param array<string, string> $errors
     */
    private function settleForm(PeriodDetail $detail, array $values, array $errors, int $status): Response
    {
        return $this->render('@Vat/settle.html.twig', [
            'key' => $detail->period->period->key,
            'title' => $this->screen->periodLabel($detail->period->period),
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: $status));
    }

    /**
     * @param array{period: string} $values
     * @param array<string, string> $errors
     */
    private function moveForm(string $movement, PeriodList $list, ?string $back, array $values, array $errors, int $status): Response
    {
        return $this->render('@Vat/move.html.twig', [
            'movement' => $movement,
            'back' => $back,
            'choices' => $this->screen->periodChoices($list),
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: $status));
    }

    /**
     * @param array{officeAccount: string, variableSymbol: string} $values
     * @param array<string, string>                                $errors
     */
    private function settingsForm(VatSettingsView $current, array $values, array $errors, int $status): Response
    {
        return $this->render('@Vat/settings.html.twig', [
            'suggestion' => $current->suggestion,
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: $status));
    }

    private function denyIfForbidden(VatRefused $refused): void
    {
        if (RefusalKind::Forbidden === $refused->kind()) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
