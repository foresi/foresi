<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Controller;

use Foresi\Sales\Application\Api\CreateBillingEntryInput;
use Foresi\Sales\Application\Api\DeleteBillingEntryInput;
use Foresi\Sales\Application\Api\LinkBillingEntryInput;
use Foresi\Sales\Application\Api\UnlinkBillingEntryInput;
use Foresi\Sales\Application\Api\UpdateBillingEntryInput;
use Foresi\Sales\Application\BillingAmounts;
use Foresi\Sales\Application\BillingDocuments;
use Foresi\Sales\Application\BillingEntryView;
use Foresi\Sales\Application\BillingPlanWrites;
use Foresi\Sales\Application\DealDetail;
use Foresi\Sales\Application\DealReads;
use Foresi\Sales\Application\SalesAccess;
use Foresi\Sales\Application\SalesRefused;
use Foresi\Sales\Application\SalesStore;
use Foresi\Sales\Contract\BillingDocumentType;
use Foresi\Sales\Domain\BillingEntryKind;
use Foresi\Sales\Domain\BillingPlanEntry;
use Foresi\Sales\Domain\DealStatus;
use Foresi\Sales\Infrastructure\Screen\DealScreen;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Infrastructure\Http\TypedNumber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The billing plan of a won deal, as Productive plans the invoices of a
 * budget: Plan invoice in the deal's header opens a dialog for an amount or a
 * share of the deal's one-off value, a day and a text; each row's menu edits
 * it, links the invoice or recurring invoice that fulfils it, unlinks it or
 * removes it. The day alone is set in a small dialog of its own, which the
 * cash-flow forecast opens for an item without a day. Without JavaScript a
 * dialog is a page of its own.
 */
final class BillingPlanController extends AbstractController
{
    public const string ENTRY_ROUTE = 'app_sales_billing_entry';
    public const string DAY_ROUTE = 'app_sales_billing_entry_day';
    /** The plan field's value for an amount; a recurring line is "line:<id>". */
    private const string AMOUNT = 'amount';
    private const string LINE = 'line:';

    public function __construct(
        private readonly DealReads $reads,
        private readonly BillingPlanWrites $writes,
        private readonly SalesStore $store,
        private readonly BillingDocuments $documents,
        private readonly DealScreen $screen,
        private readonly SalesAccess $access,
    ) {
    }

    /**
     * Where a planned invoice is read, such as from the cash-flow forecast: on its deal.
     */
    #[Route('/sales/billing-plan/{id}', name: self::ENTRY_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id): RedirectResponse
    {
        $entry = $this->entryOrNotFound($id);
        $this->detail($entry->dealId());

        return $this->redirectToRoute(DealController::DEAL_ROUTE, ['id' => $entry->dealId()]);
    }

    #[Route('/sales/deals/{id}/billing-plan/new', name: 'app_sales_billing_entry_new', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function new(string $id, Request $request): Response
    {
        $detail = $this->wonDetail($id);
        $plans = $this->planOptions($detail);
        $values = ['plan' => self::AMOUNT, 'amount' => '', 'percent' => '', 'currency' => $this->defaultCurrency($detail), 'expectedOn' => '', 'text' => ''];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = $this->posted($request, ['plan', 'amount', 'percent', 'currency', 'expectedOn', 'text']) + $values;
            try {
                if (str_starts_with($values['plan'], self::LINE)) {
                    $this->writes->create(new CreateBillingEntryInput($id, BillingEntryKind::Recurring->value, lineId: substr($values['plan'], \strlen(self::LINE)), expectedOn: $values['expectedOn'], text: $values['text']));
                } else {
                    [$amount, $percent] = $this->amountOrPercent($values, $request->getLocale());
                    $this->writes->create(new CreateBillingEntryInput($id, BillingEntryKind::OneOff->value, $amount, $values['currency'], $percent, null, $values['expectedOn'], $values['text']));
                }
                $this->addFlash('success', $this->screen->trans('sales.billing.flash.added'));

                return $this->back($id);
            } catch (SalesRefused $refused) {
                $this->denyIfForbidden($refused);
                $errors = $this->errors($refused);
            }
        }

        return $this->form($detail, null, $values, $errors, $plans);
    }

    #[Route('/sales/deals/{id}/billing-plan/{entryId}/edit', name: 'app_sales_billing_entry_edit', requirements: ['id' => Requirement::UUID, 'entryId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, string $entryId, Request $request): Response
    {
        $detail = $this->wonDetail($id);
        $view = $this->view($detail, $entryId);
        $entry = $view->entry;
        $amount = $entry->amount();
        $values = [
            'amount' => $amount instanceof Money ? $this->screen->plainAmount($amount) : '',
            'percent' => '',
            'currency' => $amount?->currency()->value ?? $this->defaultCurrency($detail),
            'expectedOn' => $entry->expectedOn()?->format('Y-m-d') ?? '',
            'text' => $entry->text(),
        ];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = $this->posted($request, ['amount', 'percent', 'currency', 'expectedOn', 'text']) + $values;
            try {
                $oneOff = BillingEntryKind::OneOff === $entry->kind();
                [$typed, $percent] = $oneOff ? $this->amountOrPercent($values, $request->getLocale()) : [null, null];
                $this->writes->update(new UpdateBillingEntryInput(
                    $id,
                    $entryId,
                    $oneOff && null !== $typed ? $typed : new Omitted(),
                    $oneOff ? $values['currency'] : new Omitted(),
                    $oneOff && null !== $percent ? $percent : new Omitted(),
                    '' === $values['expectedOn'] ? null : $values['expectedOn'],
                    $values['text'],
                ));
                $this->addFlash('success', $this->screen->trans('sales.billing.flash.saved'));

                return $this->back($id);
            } catch (SalesRefused $refused) {
                $this->denyIfForbidden($refused);
                $errors = $this->errors($refused);
            }
        }

        return $this->form($detail, $view, $values, $errors, []);
    }

    /**
     * The day alone, as the forecast sets it for an item without one: Save moves it into its period, Clear takes the day away.
     */
    #[Route('/sales/billing-plan/{id}/day', name: self::DAY_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function day(string $id, Request $request): Response
    {
        $entry = $this->entryOrNotFound($id);
        $detail = $this->wonDetail($entry->dealId());
        $view = $this->view($detail, $id);
        $return = DealScreen::localPath($request->query->getString('return'));
        $values = ['expectedOn' => $entry->expectedOn()?->format('Y-m-d') ?? ''];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $clear = '1' === $request->request->getString('clear');
            $values = ['expectedOn' => $clear ? '' : trim($request->request->getString('expectedOn'))];
            if (!$clear && '' === $values['expectedOn']) {
                $errors = ['expectedOn' => $this->screen->trans('sales.billing.error.day_required')];
            } else {
                try {
                    $this->writes->update(new UpdateBillingEntryInput($entry->dealId(), $id, expectedOn: '' === $values['expectedOn'] ? null : $values['expectedOn']));
                    $this->addFlash('success', $this->screen->trans($clear ? 'sales.billing.flash.day_cleared' : 'sales.billing.flash.day_saved'));

                    return null === $return ? $this->back($entry->dealId()) : $this->redirect($return, Response::HTTP_SEE_OTHER);
                } catch (SalesRefused $refused) {
                    $this->denyIfForbidden($refused);
                    $errors = $this->errors($refused);
                }
            }
        }

        return $this->render('@Sales/billing/day.html.twig', [
            'deal' => $detail->deal,
            'label' => $this->label($view),
            'action' => $this->generateUrl(self::DAY_ROUTE, ['id' => $id] + (null === $return ? [] : ['return' => $return])),
            'back' => $return ?? $this->generateUrl(DealController::DEAL_ROUTE, ['id' => $entry->dealId()]),
            'values' => $values,
            'errors' => $errors,
            'set' => $entry->expectedOn() instanceof \DateTimeImmutable,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * Links an invoice or recurring invoice of the client that exists already, such as one issued before the plan.
     */
    #[Route('/sales/deals/{id}/billing-plan/{entryId}/link', name: 'app_sales_billing_entry_link', requirements: ['id' => Requirement::UUID, 'entryId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function link(string $id, string $entryId, Request $request): Response
    {
        $detail = $this->wonDetail($id);
        $view = $this->view($detail, $entryId);
        $type = BillingEntryKind::OneOff === $view->entry->kind() ? BillingDocumentType::Invoice : BillingDocumentType::RecurringInvoice;
        $options = [];
        foreach ($this->documents->ofClient($type, $detail->deal->clientPartyId()) as $document) {
            $options[$document->id] = $document->label;
        }
        $values = ['documentId' => (string) array_key_first($options)];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = $this->posted($request, ['documentId']);
            try {
                $this->writes->link(new LinkBillingEntryInput($id, $entryId, $type->value, $values['documentId']));
                $this->addFlash('success', $this->screen->trans('sales.billing.flash.linked'));

                return $this->back($id);
            } catch (SalesRefused $refused) {
                $this->denyIfForbidden($refused);
                $errors = ['documentId' => $this->screen->refusal($refused)];
            }
        }

        return $this->render('@Sales/billing/link.html.twig', [
            'deal' => $detail->deal,
            'label' => $this->label($view),
            'action' => $this->generateUrl('app_sales_billing_entry_link', ['id' => $id, 'entryId' => $entryId]),
            'type' => $type->value,
            'options' => $options,
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/sales/deals/{id}/billing-plan/{entryId}/unlink', name: 'app_sales_billing_entry_unlink', requirements: ['id' => Requirement::UUID, 'entryId' => Requirement::UUID], methods: ['POST'])]
    public function unlink(string $id, string $entryId, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->act(fn (): mixed => $this->writes->unlink(new UnlinkBillingEntryInput($id, $entryId)), 'sales.billing.flash.unlinked');

        return $this->back($id);
    }

    #[Route('/sales/deals/{id}/billing-plan/{entryId}/delete', name: 'app_sales_billing_entry_delete', requirements: ['id' => Requirement::UUID, 'entryId' => Requirement::UUID], methods: ['POST'])]
    public function delete(string $id, string $entryId, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->act(fn (): mixed => $this->writes->delete(new DeleteBillingEntryInput($id, $entryId)), 'sales.billing.flash.deleted');

        return $this->back($id);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     * @param array<string, string> $plans  what a new entry plans: an amount, or a recurring line without an entry
     */
    private function form(DealDetail $detail, ?BillingEntryView $view, array $values, array $errors, array $plans): Response
    {
        $deal = $detail->deal;
        // The currencies of the deal's one-off value, or CZK for a deal without one.
        $currencies = [] === $detail->value->oneOff ? ['CZK'] : array_keys($detail->value->oneOff);

        return $this->render('@Sales/billing/form.html.twig', [
            'deal' => $deal,
            'entry' => $view?->entry,
            'recurring' => BillingEntryKind::Recurring === $view?->entry->kind(),
            'label' => $view instanceof BillingEntryView ? $this->label($view) : null,
            'action' => $view instanceof BillingEntryView
                ? $this->generateUrl('app_sales_billing_entry_edit', ['id' => $deal->id(), 'entryId' => $view->entry->id()])
                : $this->generateUrl('app_sales_billing_entry_new', ['id' => $deal->id()]),
            'values' => $values,
            'errors' => $errors,
            'plans' => $plans,
            'currencies' => array_combine($currencies, $currencies),
            'oneOffValue' => $this->screen->amounts($detail->value->oneOff),
            'notPlanned' => $this->screen->amounts($detail->billingPlan->notPlanned),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * An amount, or each priced recurring line that has no entry yet; an
     * hourly line is never planned.
     *
     * @return array<string, string>
     */
    private function planOptions(DealDetail $detail): array
    {
        $options = [self::AMOUNT => $this->screen->trans('sales.billing.plan.amount')];
        $planned = array_filter(array_map(static fn (BillingEntryView $view): ?string => $view->entry->lineId(), $detail->billingPlan->entries), static fn (?string $lineId): bool => null !== $lineId);
        foreach ($detail->lines as $line) {
            $amount = BillingAmounts::ofLine($line);
            if ($amount instanceof Money && !\in_array($line->id(), $planned, true)) {
                $options[self::LINE.$line->id()] = $line->name().' · '.$this->screen->lineTotal($line);
            }
        }

        return $options;
    }

    /**
     * The amount typed, as the use case takes it, or the percent; the percent wins when both are typed.
     *
     * @param array<string, string> $values
     *
     * @return array{?string, ?string}
     */
    private function amountOrPercent(array $values, string $locale): array
    {
        $percent = TypedNumber::decimal($values['percent'], $locale);
        if ('' !== $percent) {
            return [null, $percent];
        }
        $amount = TypedNumber::decimal($values['amount'], $locale);

        return ['' === $amount ? '' : $amount, null];
    }

    private function defaultCurrency(DealDetail $detail): string
    {
        return array_key_first($detail->value->oneOff) ?? 'CZK';
    }

    private function label(BillingEntryView $view): string
    {
        if ('' !== $view->entry->text()) {
            return $view->entry->text();
        }

        return $view->line?->name() ?? $this->screen->trans('sales.billing.kind.'.$view->entry->kind()->value);
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string, string>
     */
    private function posted(Request $request, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = trim($request->request->getString($field));
        }

        return $values;
    }

    /**
     * @return array<string, string> the message of the refused field, by form field
     */
    private function errors(SalesRefused $refused): array
    {
        $field = $refused->field();
        $field = match ($field) {
            'amount', 'percent', 'currency', 'expectedOn', 'text' => $field,
            'lineId', 'kind' => 'plan',
            default => 'amount',
        };

        return [$field => $this->screen->refusal($refused)];
    }

    /**
     * @param \Closure(): mixed $write
     */
    private function act(\Closure $write, string $flash): void
    {
        try {
            $write();
            $this->addFlash('success', $this->screen->trans($flash));
        } catch (SalesRefused $refused) {
            $this->denyIfForbidden($refused);
            if (RefusalKind::NotFound === $refused->kind()) {
                throw new NotFoundHttpException($refused->getMessage(), $refused);
            }
            $this->addFlash('error', $this->screen->refusal($refused));
        }
    }

    private function detail(string $id): DealDetail
    {
        try {
            return $this->reads->deal($id);
        } catch (SalesRefused $refused) {
            $this->denyIfForbidden($refused);
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
    }

    /**
     * A won deal not in the trash, whose plan may change.
     */
    private function wonDetail(string $id): DealDetail
    {
        $detail = $this->detail($id);
        if ($detail->deal->isTrashed() || DealStatus::Won !== $detail->deal->status()) {
            throw new NotFoundHttpException('Only a won deal not in the trash has a billing plan to change.');
        }

        return $detail;
    }

    private function view(DealDetail $detail, string $entryId): BillingEntryView
    {
        foreach ($detail->billingPlan->entries as $view) {
            if ($view->entry->id() === $entryId) {
                return $view;
            }
        }
        throw new NotFoundHttpException('The deal has no such planned invoice.');
    }

    private function entryOrNotFound(string $id): BillingPlanEntry
    {
        // Only staff reads a plan; a guest never learns whether the entry exists.
        if (!$this->access->mayWork()) {
            throw new AccessDeniedHttpException('Only the owners and members of the organisation work with its deals.');
        }

        return $this->store->billingEntry($id) ?? throw new NotFoundHttpException('There is no such planned invoice.');
    }

    private function back(string $id): RedirectResponse
    {
        return $this->redirectToRoute(DealController::DEAL_ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    private function denyIfForbidden(SalesRefused $refused): void
    {
        if (RefusalKind::Forbidden === $refused->kind()) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(DealController::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
