<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Controller;

use Foresi\CashFlow\Application\CashFlowAccess;
use Foresi\CashFlow\Application\CashFlowRefused;
use Foresi\CashFlow\Application\ChangePlannedItemInput;
use Foresi\CashFlow\Application\CreatePlannedItemInput;
use Foresi\CashFlow\Application\DeletePlannedItemInput;
use Foresi\CashFlow\Application\EndPlannedItemInput;
use Foresi\CashFlow\Application\PlannedItemReads;
use Foresi\CashFlow\Application\PlannedItemWrites;
use Foresi\CashFlow\Application\SkipPlannedOccurrenceInput;
use Foresi\CashFlow\Contract\FlowDirection;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\CashFlow\Infrastructure\Screen\PlannedItemScreen;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Domain\Schedule\RecurrenceEnd;
use Foresi\Shared\Infrastructure\Http\TypedNumber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Planned income and Planned costs, two pages under Money next to the Cash
 * flow: money the firm expects that no document carries yet, kept outside the
 * books. Pulse adds income and expenses apart and QuickBooks asks money in or
 * money out first, so each page has one direction and its "+ New" dialog does
 * not ask it. A row opens the item in the side sheet, which edits it, leaves it
 * out of the forecast or includes it (Pulse's Active, Float's switch), skips one
 * occurrence (Xero, Pulse), ends it and deletes it. Only the owners and members
 * of the organisation see them.
 */
final class PlannedItemController extends AbstractController
{
    public const string TOKEN = 'cash_flow_planned';
    private const int MAX_QUERY = 100;

    public function __construct(
        private readonly PlannedItemReads $reads,
        private readonly PlannedItemWrites $writes,
        private readonly PlannedItemScreen $screen,
        private readonly CashFlowAccess $access,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/cash-flow/planned-income', name: 'app_cash_flow_planned_income', defaults: ['direction' => 'in'], methods: ['GET'])]
    #[Route('/cash-flow/planned-costs', name: 'app_cash_flow_planned_costs', defaults: ['direction' => 'out'], methods: ['GET'])]
    public function index(string $direction): Response
    {
        $direction = FlowDirection::from($direction);
        $items = $this->guarded(fn (): array => $this->reads->all());
        if (null === $items) {
            return $this->render('@CashFlow/planned/index.html.twig', ['noFirm' => true, 'direction' => $direction->value, 'title' => $this->screen->title($direction)]);
        }

        return $this->render('@CashFlow/planned/index.html.twig', [
            'noFirm' => false,
            'direction' => $direction->value,
            'title' => $this->screen->title($direction),
            'columns' => $this->screen->columns(),
            'rows' => $this->screen->rows(array_values(array_filter($items, static fn (PlannedItem $item): bool => $item->direction() === $direction))),
        ]);
    }

    #[Route('/cash-flow/planned-income/new', name: 'app_cash_flow_planned_income_new', defaults: ['direction' => 'in'], methods: ['GET', 'POST'])]
    #[Route('/cash-flow/planned-costs/new', name: 'app_cash_flow_planned_costs_new', defaults: ['direction' => 'out'], methods: ['GET', 'POST'])]
    public function new(string $direction, Request $request): Response
    {
        $direction = FlowDirection::from($direction);
        $this->guarded(function (): mixed {
            $this->access->denyUnlessStaff();

            return $this->access->firm();
        }) ?? throw new NotFoundHttpException();
        $values = $this->screen->values($direction, null, $request->getLocale());
        if (!$request->isMethod('POST')) {
            return $this->form($direction, null, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request);
        $values = $this->posted($request);
        try {
            $d = $this->draft($values, $request->getLocale());
            $item = $this->writes->create(new CreatePlannedItemInput(
                $direction->value, $d['name'], $d['category'], $d['amount'], $d['on'], $d['currency'], $d['repeat'], $d['every'], $d['lastDayOfMonth'],
                $d['endsOn'], $d['endsAfter'], $d['counterpartyId'], $d['included'], $d['matchAccount'], $d['matchVariableSymbol'], $d['matchTolerance'],
                $d['vatMode'], $d['vatRate'],
            ));
        } catch (CashFlowRefused $refused) {
            $this->denyIfForbidden($refused);

            return $this->form($direction, null, $values, $this->errors($refused), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('cash_flow.planned.flash.created', ['name' => $item->name()]));

        return $this->redirectToRoute(PlannedItemScreen::listRoute($direction));
    }

    #[Route('/cash-flow/planned-income/{id}', name: 'app_cash_flow_planned_income_item', requirements: ['id' => Requirement::UUID], defaults: ['direction' => 'in'], methods: ['GET'])]
    #[Route('/cash-flow/planned-costs/{id}', name: 'app_cash_flow_planned_costs_item', requirements: ['id' => Requirement::UUID], defaults: ['direction' => 'out'], methods: ['GET'])]
    public function show(string $id, string $direction): Response
    {
        $item = $this->item($id, $direction);

        return $this->render('@CashFlow/planned/show.html.twig', [
            'item' => $item,
            'direction' => $item->direction()->value,
            'listTitle' => $this->screen->title($item->direction()),
            'listRoute' => PlannedItemScreen::listRoute($item->direction()),
            'itemRoute' => PlannedItemScreen::itemRoute($item->direction()),
            'details' => $this->screen->details($item),
            'occurrences' => $this->screen->occurrences($item),
            'pairedDocuments' => $this->screen->pairedDocuments($item),
            'recurring' => $item->isRecurring(),
        ]);
    }

    #[Route('/cash-flow/planned-income/{id}/edit', name: 'app_cash_flow_planned_income_item_edit', requirements: ['id' => Requirement::UUID], defaults: ['direction' => 'in'], methods: ['GET', 'POST'])]
    #[Route('/cash-flow/planned-costs/{id}/edit', name: 'app_cash_flow_planned_costs_item_edit', requirements: ['id' => Requirement::UUID], defaults: ['direction' => 'out'], methods: ['GET', 'POST'])]
    public function edit(string $id, string $direction, Request $request): Response
    {
        $item = $this->item($id, $direction);
        $values = $this->screen->values($item->direction(), $item, $request->getLocale());
        if (!$request->isMethod('POST')) {
            return $this->form($item->direction(), $item, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request);
        $values = $this->posted($request);
        try {
            $d = $this->draft($values, $request->getLocale());
            $this->writes->change(new ChangePlannedItemInput(
                $id, $d['name'], $d['category'], $d['amount'], $d['currency'], $d['on'], $d['repeat'], $d['every'], $d['lastDayOfMonth'],
                $d['endsOn'], $d['endsAfter'], $d['counterpartyId'], $d['included'], $d['matchAccount'], $d['matchVariableSymbol'], $d['matchTolerance'],
                $d['vatMode'], $d['vatRate'],
            ));
        } catch (CashFlowRefused $refused) {
            $this->denyIfForbidden($refused);

            return $this->form($item->direction(), $item, $values, $this->errors($refused), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('cash_flow.planned.flash.saved'));

        return $this->redirectToRoute(PlannedItemScreen::itemRoute($item->direction()), ['id' => $id]);
    }

    /**
     * Includes the item in the forecast or leaves it out, keeping it for a "what if".
     */
    #[Route('/cash-flow/planned/{id}/include', name: 'app_cash_flow_planned_include', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function include(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $included = '1' === $request->request->getString('included');

        return $this->act($this->item($id), fn (): mixed => $this->writes->change(new ChangePlannedItemInput($id, included: $included)), $included ? 'cash_flow.planned.flash.included' : 'cash_flow.planned.flash.left_out');
    }

    #[Route('/cash-flow/planned/{id}/skip', name: 'app_cash_flow_planned_skip', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function skip(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $on = $request->request->getString('on');

        return $this->act($this->item($id), fn (): mixed => $this->writes->skip(new SkipPlannedOccurrenceInput($id, $on)), 'cash_flow.planned.flash.skipped');
    }

    /**
     * Ends a recurring item on a day, in a small dialog.
     */
    #[Route('/cash-flow/planned-income/{id}/end', name: 'app_cash_flow_planned_income_item_end', requirements: ['id' => Requirement::UUID], defaults: ['direction' => 'in'], methods: ['GET', 'POST'])]
    #[Route('/cash-flow/planned-costs/{id}/end', name: 'app_cash_flow_planned_costs_item_end', requirements: ['id' => Requirement::UUID], defaults: ['direction' => 'out'], methods: ['GET', 'POST'])]
    public function end(string $id, string $direction, Request $request): Response
    {
        $item = $this->item($id, $direction);
        if (!$item->isRecurring()) {
            throw new NotFoundHttpException();
        }
        // The next occurrence is the last one by default: the item stops after it.
        $values = ['on' => ($this->screen->next($item) ?? max($this->clock->today(), $item->firstOn()))->format('Y-m-d')];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = ['on' => trim($request->request->getString('on'))];
            try {
                $this->writes->end(new EndPlannedItemInput($id, $values['on']));
                $this->addFlash('success', $this->translator->trans('cash_flow.planned.flash.ended'));

                return $this->redirectToRoute(PlannedItemScreen::itemRoute($item->direction()), ['id' => $id]);
            } catch (CashFlowRefused $refused) {
                $this->denyIfForbidden($refused);
                $errors = ['on' => $this->translator->trans('cash_flow.planned.error.'.$refused->reason())];
            }
        }

        return $this->render('@CashFlow/planned/end.html.twig', [
            'item' => $item,
            'listTitle' => $this->screen->title($item->direction()),
            'listRoute' => PlannedItemScreen::listRoute($item->direction()),
            'itemRoute' => PlannedItemScreen::itemRoute($item->direction()),
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/cash-flow/planned/{id}/delete', name: 'app_cash_flow_planned_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $item = $this->item($id);
        try {
            $this->writes->delete(new DeletePlannedItemInput($id));
        } catch (CashFlowRefused $refused) {
            $this->denyIfForbidden($refused);
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
        $this->addFlash('success', $this->translator->trans('cash_flow.planned.flash.deleted', ['name' => $item->name()]));

        return $this->redirectToRoute(PlannedItemScreen::listRoute($item->direction()));
    }

    /**
     * The contacts the counterparty field offers.
     */
    #[Route('/cash-flow/planned/contacts', name: 'app_cash_flow_planned_contacts', methods: ['GET'])]
    public function contacts(Request $request): JsonResponse
    {
        $results = $this->guarded(fn (): array => $this->access->contacts(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY))) ?? [];

        return $this->json(['results' => $results]);
    }

    /**
     * @param \Closure(): mixed $write
     */
    private function act(PlannedItem $item, \Closure $write, string $flash): RedirectResponse
    {
        try {
            $write();
            $this->addFlash('success', $this->translator->trans($flash));
        } catch (CashFlowRefused $refused) {
            $this->denyIfForbidden($refused);
            if (RefusalKind::NotFound === $refused->kind()) {
                throw new NotFoundHttpException($refused->getMessage(), $refused);
            }
            $this->addFlash('error', $this->translator->trans('cash_flow.planned.error.'.$refused->reason()));
        }

        return $this->redirectToRoute(PlannedItemScreen::itemRoute($item->direction()), ['id' => $item->id()]);
    }

    /**
     * What a read gives, or null before the first-run setup gave the instance its firm.
     *
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T|null
     */
    private function guarded(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (CashFlowRefused $refused) {
            $this->denyIfForbidden($refused);

            return null;
        }
    }

    /**
     * The item, of the direction of the page when the URL names one.
     */
    private function item(string $id, ?string $direction = null): PlannedItem
    {
        try {
            $item = $this->reads->one($id);
        } catch (CashFlowRefused $refused) {
            $this->denyIfForbidden($refused);
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
        if (null !== $direction && $item->direction()->value !== $direction) {
            throw new NotFoundHttpException();
        }

        return $item;
    }

    /**
     * @return array<string, string>
     */
    private function posted(Request $request): array
    {
        $values = [];
        foreach (['name', 'category', 'amount', 'currency', 'on', 'repeat', 'every', 'end', 'endsOn', 'endsAfter', 'counterpartyId', 'counterpartyQuery', 'matchAccount', 'matchVariableSymbol', 'matchTolerance'] as $field) {
            $values[$field] = trim($request->request->getString($field));
        }
        $values['lastDayOfMonth'] = '1' === $request->request->getString('lastDayOfMonth') ? '1' : '';
        $values['included'] = '1' === $request->request->getString('included') ? '1' : '';
        // A cleared contact field keeps no contact, whatever its hidden id still says.
        if ('' === $values['counterpartyQuery']) {
            $values['counterpartyId'] = '';
        }
        $values['vatMode'] = trim($request->request->getString('vatMode'));
        $values['vatRate'] = trim($request->request->getString('vatRate'));

        return $values;
    }

    /**
     * The form's values as the use cases take them: a one-off item sends no
     * schedule, and only the end chosen is sent.
     *
     * @param array<string, string> $values
     *
     * @return array{name: string, category: string, amount: string, currency: string, on: string, repeat: ?string, every: int, lastDayOfMonth: bool, endsOn: ?string, endsAfter: ?int, counterpartyId: ?string, included: bool, matchAccount: ?string, matchVariableSymbol: ?string, matchTolerance: ?string, vatMode: string, vatRate: ?string}
     *
     * @throws CashFlowRefused
     */
    private function draft(array $values, string $locale): array
    {
        if ('' === $values['amount']) {
            throw CashFlowRefused::invalid('amount_required', 'amount', 'Enter the amount.');
        }
        $repeat = '' === $values['repeat'] ? null : $values['repeat'];
        $every = 1;
        if (null !== $repeat) {
            if (1 !== preg_match('/^\d{1,2}$/', $values['every'])) {
                throw CashFlowRefused::invalid('every_invalid', 'every', 'Every is a whole number from 1 to 99.');
            }
            $every = (int) $values['every'];
        }
        $endsAfter = null;
        if (null !== $repeat && RecurrenceEnd::AfterCount->value === $values['end']) {
            if (1 !== preg_match('/^\d{1,3}$/', $values['endsAfter'])) {
                throw CashFlowRefused::invalid('ends_after_invalid', 'endsAfter', 'The count is a whole number from 1 to 999.');
            }
            $endsAfter = (int) $values['endsAfter'];
        }
        $tolerance = TypedNumber::decimal($values['matchTolerance'], $locale);
        // Without a VAT mode the rate field is ignored, as the form hides it.
        $vatMode = '' === $values['vatMode'] ? 'none' : $values['vatMode'];
        $vatRate = 'none' === $vatMode ? '' : TypedNumber::decimal($values['vatRate'], $locale);

        return [
            'name' => $values['name'],
            'category' => $values['category'],
            'amount' => TypedNumber::decimal($values['amount'], $locale),
            'currency' => $values['currency'],
            'on' => $values['on'],
            'repeat' => $repeat,
            'every' => $every,
            'lastDayOfMonth' => null !== $repeat && '1' === $values['lastDayOfMonth'],
            'endsOn' => null !== $repeat && RecurrenceEnd::OnDate->value === $values['end'] ? $values['endsOn'] : null,
            'endsAfter' => $endsAfter,
            'counterpartyId' => '' === $values['counterpartyId'] ? null : $values['counterpartyId'],
            'included' => '1' === $values['included'],
            'matchAccount' => '' === $values['matchAccount'] ? null : $values['matchAccount'],
            'matchVariableSymbol' => '' === $values['matchVariableSymbol'] ? null : $values['matchVariableSymbol'],
            'matchTolerance' => '' === $tolerance ? null : $tolerance,
            'vatMode' => $vatMode,
            'vatRate' => '' === $vatRate ? null : $vatRate,
        ];
    }

    /**
     * @return array<string, string> the message of the refused field, by form field
     */
    private function errors(CashFlowRefused $refused): array
    {
        $field = match ($refused->field()) {
            'endsOn' => 'endsOn',
            'endsAfter' => 'endsAfter',
            'counterpartyId' => 'counterpartyQuery',
            'name', 'category', 'amount', 'currency', 'on', 'repeat', 'every', 'matchAccount', 'matchVariableSymbol', 'matchTolerance', 'vatMode', 'vatRate' => $refused->field(),
            default => 'name',
        };

        return [$field => $this->translator->trans('cash_flow.planned.error.'.$refused->reason())];
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     */
    private function form(FlowDirection $direction, ?PlannedItem $item, array $values, array $errors, int $status): Response
    {
        return $this->render('@CashFlow/planned/form.html.twig', [
            'item' => $item,
            'direction' => $direction->value,
            'listTitle' => $this->screen->title($direction),
            'listRoute' => PlannedItemScreen::listRoute($direction),
            'itemRoute' => PlannedItemScreen::itemRoute($direction),
            'action' => $item instanceof PlannedItem
                ? $this->generateUrl(PlannedItemScreen::itemRoute($direction).'_edit', ['id' => $item->id()])
                : $this->generateUrl(PlannedItemScreen::listRoute($direction).'_new'),
            'values' => $values,
            'errors' => $errors,
            // A match rule with a value, or one refused, shows unfolded.
            'matchOpen' => '' !== $values['matchAccount'].$values['matchVariableSymbol'].$values['matchTolerance']
                || [] !== array_intersect_key($errors, ['matchAccount' => true, 'matchVariableSymbol' => true, 'matchTolerance' => true]),
        ] + $this->screen->options($direction), new Response(status: $status));
    }

    private function denyIfForbidden(CashFlowRefused $refused): void
    {
        if (RefusalKind::Forbidden === $refused->kind()) {
            throw new AccessDeniedHttpException();
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
