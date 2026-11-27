<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Controller;

use Foresi\Deals\Application\Api\AddDealLinkInput;
use Foresi\Deals\Application\Api\ChangeDealStageInput;
use Foresi\Deals\Application\Api\CreateDealInput;
use Foresi\Deals\Application\Api\CreateFollowUpDealInput;
use Foresi\Deals\Application\Api\LoseDealInput;
use Foresi\Deals\Application\Api\RemoveDealLinkInput;
use Foresi\Deals\Application\Api\ReopenDealInput;
use Foresi\Deals\Application\Api\RestoreDealInput;
use Foresi\Deals\Application\Api\TrashDealInput;
use Foresi\Deals\Application\Api\UpdateDealInput;
use Foresi\Deals\Application\Api\WinDealInput;
use Foresi\Deals\Application\BillingEntryView;
use Foresi\Deals\Application\ClientPersonRefused;
use Foresi\Deals\Application\ClientPersons;
use Foresi\Deals\Application\ContactPersonDraft;
use Foresi\Deals\Application\DealDetail;
use Foresi\Deals\Application\DealLinkView;
use Foresi\Deals\Application\DealLinkWrites;
use Foresi\Deals\Application\DealReads;
use Foresi\Deals\Application\DealValue;
use Foresi\Deals\Application\DealValues;
use Foresi\Deals\Application\DealWrites;
use Foresi\Deals\Application\LineProject;
use Foresi\Deals\Application\LineProjects;
use Foresi\Deals\Application\SalesAccess;
use Foresi\Deals\Application\SalesRefused;
use Foresi\Deals\Application\SalesStore;
use Foresi\Deals\Application\View\DealLists;
use Foresi\Deals\Application\View\DealQueries;
use Foresi\Deals\Application\View\DealRow;
use Foresi\Deals\Contract\BillingDocument;
use Foresi\Deals\Contract\Field\DealExtension;
use Foresi\Deals\Contract\SalesSlots;
use Foresi\Deals\Domain\BillingEntryKind;
use Foresi\Deals\Domain\Deal;
use Foresi\Deals\Domain\DealLine;
use Foresi\Deals\Domain\DealStatus;
use Foresi\Deals\Domain\LostReason;
use Foresi\Deals\Domain\Stage;
use Foresi\Deals\Infrastructure\Column\DealColumns;
use Foresi\Deals\Infrastructure\Screen\DealScreen;
use Foresi\Party\Application\PartyQuery;
use Foresi\Party\Application\PartyRow;
use Foresi\Party\Contract\ContactKind;
use Foresi\Party\Contract\ContactPages;
use Foresi\Party\Contract\PartyDirectory;
use Foresi\Party\Contract\PartyPersonSummary;
use Foresi\Party\Contract\PartySummary;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Field\FieldRefused;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\ViewState;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\Guard;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\Field\CustomFieldForm;
use Foresi\Ui\Field\CustomFieldSubmission;
use Foresi\Ui\Slot\SlotRegistry;
use Foresi\Ui\View\ViewBar;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The deals, for the owners and members. The list runs on saved views as
 * every list does (All, Open, Won, Lost, Trash, the filter sheet, Columns) and
 * groups the open deals by stage, one table per stage with its count and value,
 * as Odoo's list grouped by stage shows them, and the won and the lost deals
 * in a table each after them. A deal is a page of its own:
 * its header holds Won or Reopen, one prominent action of another module and
 * the rest in "⋯"; its main column the lines and their value, and the
 * sections other modules add as tabs (?tab=), the first by default; its side
 * column the details, the notes and the linked deals. New, Edit, Lost, a
 * follow-up and a link each open in a dialog; without JavaScript every dialog
 * is a page of its own. New and Edit offer the client's contact persons, and a
 * person to add to the client on the way, from the address book or new: the
 * dialog cannot open a dialog over itself (dialog_controller), so it happens
 * inside it, and the persons reload when the client changes (deal-client).
 * The custom fields of a deal (ADR 0045) are in New and Edit, under "Additional
 * details", and in the side column of the deal.
 */
final class DealController extends AbstractController
{
    public const string ROUTE = 'app_sales_deals';
    public const string NEW_ROUTE = 'app_sales_deal_new';
    public const string DEAL_ROUTE = 'app_sales_deal';
    public const string TOKEN = 'sales';
    public const string UNDO_FLASH = 'sales_undo';
    public const string PERSON_EXISTING = 'existing';
    public const string PERSON_NEW = 'new';

    /** The list's one sort, by title; "-title" backwards. */
    private const string SORT_TITLE = 'title';
    private const int MAX_QUERY = 100;
    private const int MAX_SEARCH = 100;

    public function __construct(
        private readonly DealLists $dealLists,
        private readonly DealQueries $queries,
        private readonly DealReads $reads,
        private readonly DealWrites $writes,
        private readonly DealLinkWrites $links,
        private readonly SalesStore $store,
        private readonly SalesAccess $access,
        private readonly DealScreen $screen,
        private readonly PartyDirectory $parties,
        private readonly PartyQuery $partyQuery,
        private readonly ListViews $listViews,
        private readonly ActorRole $actorRole,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
        private readonly SlotRegistry $slots,
        private readonly LineProjects $lineProjects,
        private readonly CustomFieldForm $customFields,
        private readonly ?ClientPersons $clientPersons = null,
        private readonly ?ContactPages $contactPages = null,
    ) {
    }

    #[Route('/sales/deals', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        $input = $request->query;
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($input->has(FilterRows::MARKER)) {
            return $this->redirectToRoute(self::ROUTE, $this->builtQuery($input), Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->views($input);
        $list = $this->dealLists->list();
        $filterParam = WorkingFilter::param($shown, $view->filter);
        $text = trim(mb_substr($input->getString('q'), 0, self::MAX_SEARCH));
        $viewSort = ViewState::sortParam($view->sort, self::SORT_TITLE);
        $sort = $input->getString('sort', $viewSort);
        $sort = self::SORT_TITLE === ltrim($sort, '-') ? $sort : self::SORT_TITLE;
        $query = array_filter(['view' => $view->isAll() ? null : $view->key, 'q' => '' === $text ? null : $text, 'sort' => $viewSort === $sort ? null : $sort, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value);
        $working = $this->rememberedDisplay->read(DealColumns::LIST, [], $list->key, $view, $input->all(), filters: $list);
        $query += $working->query;
        $bar = $this->viewBar->of($list, $views, $view, $shown, $sort, self::SORT_TITLE, $working, self::ROUTE, '' === $text ? [] : ['q' => $text]);
        // The clients whose name the text matches: their deals are found by it as well as the deals by title.
        $clientIds = '' === $text ? [] : $this->partyQuery->matchingIds($text, null, false);
        $sections = $this->sections($this->queries->index($shown, str_starts_with($sort, '-'), $text, $clientIds), $working);

        return $this->render('@Deals/deals/index.html.twig', [
            'view' => $view->isAll() ? '' : $view->key,
            'emptyTitle' => $this->screen->trans(DealLists::TRASHED === $view->key ? 'deals.deals.empty.trash_title' : 'deals.deals.empty.view_title'),
            'working' => $filterParam ?? '',
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl(self::ROUTE, [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), $this->references(), pinned: $working->pinned),
            'sortKept' => $viewSort === $sort ? null : $sort,
            ...$bar,
            ...$this->tabs($views, $view->key, $query, $bar['viewBar']['modified']),
            'columns' => $working->display->table($sort, fn (string $next): string => $this->generateUrl(self::ROUTE, ['sort' => $next] + $query)),
            'sections' => $sections,
            'picker' => $this->picker($working, $query),
            'workingQuery' => $working->query,
            'text' => $text,
            'filtered' => '' !== $text || null !== $filterParam,
            'resetHref' => $this->generateUrl(self::ROUTE, ['view' => $view->isAll() ? null : $view->key]),
        ]);
    }

    /**
     * The contacts the client field and the client filter offer: any contact of the address book.
     */
    #[Route('/sales/deals/clients', name: 'app_sales_deal_clients', methods: ['GET'])]
    public function clients(Request $request): JsonResponse
    {
        $this->denyUnlessStaff();
        $page = $this->partyQuery->search(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY), null, false, 'name', 1);

        return $this->json(['results' => array_map(fn (PartyRow $row): array => [
            'id' => $row->id,
            'label' => $row->displayName,
            'hint' => $row->email ?? $row->registrationNumber ?? $this->screen->trans('party.kind.'.$row->kind->value),
        ], $page->rows)]);
    }

    /**
     * The active stages the stage filter offers, in their order.
     */
    #[Route('/sales/deals/stages', name: 'app_sales_deal_stages', methods: ['GET'])]
    public function stages(Request $request): JsonResponse
    {
        $this->denyUnlessStaff();
        $text = mb_strtolower(trim(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY)));
        $results = [];
        foreach ($this->reads->stages() as $stage) {
            $name = $this->screen->stage($stage);
            if ('' === $text || str_contains(mb_strtolower($name), $text)) {
                $results[] = ['id' => $stage->id(), 'label' => $name];
            }
        }

        return $this->json(['results' => $results]);
    }

    /**
     * The owners and members the owner filter offers.
     */
    #[Route('/sales/deals/owners', name: 'app_sales_deal_owners', methods: ['GET'])]
    public function owners(Request $request): JsonResponse
    {
        $this->denyUnlessStaff();

        return $this->json(['results' => $this->screen->ownerResults(mb_substr($request->query->getString('q'), 0, self::MAX_QUERY))]);
    }

    /**
     * The contact persons of a client and the person to add to it, as the deal's dialog
     * shows them once the client is chosen (deal-client). Nothing for an unknown client.
     */
    #[Route('/sales/deals/client-persons', name: 'app_sales_deal_client_persons', methods: ['GET'])]
    public function clientPersons(Request $request): Response
    {
        $this->denyUnlessStaff();
        $clientId = trim($request->query->getString('client'));
        $client = '' === $clientId ? null : $this->parties->find($clientId);

        return $this->render('@Deals/deals/_persons.html.twig', ['values' => ['contactPersonIds' => []], 'errors' => [], ...$this->personsBlock($client, $this->personValues(), [])]);
    }

    /**
     * A new deal in a dialog: its title, client, owner, stage and notes. Opened from a
     * contact (?client=<id>) the client is set; the client's contact persons are offered
     * once it is known, with a person to add.
     */
    #[Route('/sales/deals/new', name: self::NEW_ROUTE, methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $userId = $this->denyUnlessStaff();
        $source = $request->isMethod('POST') ? $request->request : $request->query;
        $clientId = trim($source->getString($request->isMethod('POST') ? 'clientId' : 'client'));
        $client = '' === $clientId ? null : $this->parties->find($clientId);
        $stages = $this->reads->stages();
        $values = [
            'title' => '',
            'clientId' => $client instanceof PartySummary ? $client->id : '',
            'clientQuery' => '',
            'ownerUserId' => $userId,
            'stageId' => [] === $stages ? '' : $stages[0]->id(),
            'description' => '',
            'contactPersonIds' => [],
        ];
        $person = $this->personValues();
        $errors = [];
        $custom = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = $this->postedDeal($request) + $values;
            $values['clientId'] = $clientId;
            $person = $this->personValues($request);
            $custom = $this->customFields->read(DealExtension::RECORD, $request, true);
            if ($custom->valid()) {
                try {
                    $deal = $this->writes->create(new CreateDealInput($values['title'], $values['clientId'], '' === $values['stageId'] ? null : $values['stageId'], '' === $values['ownerUserId'] ? null : $values['ownerUserId'], $values['description'], $values['contactPersonIds']), $this->draft($person), $custom->parsed);
                    $this->addFlash('success', $this->screen->trans('deals.deal.flash.created', ['title' => $deal->title()]));

                    return $this->redirectToRoute(self::DEAL_ROUTE, ['id' => $deal->id()], Response::HTTP_SEE_OTHER);
                } catch (SalesRefused $refused) {
                    $this->denyIfForbidden($refused);
                    $errors = $this->errors($refused);
                } catch (ClientPersonRefused $refused) {
                    $errors = $this->personErrors($refused, $person);
                } catch (FieldRefused $refused) {
                    $custom = $custom->refused($refused->key, $this->screen->trans($refused->translationKey));
                }
            }
        }

        return $this->render('@Deals/deals/form.html.twig', [
            'deal' => null,
            'action' => $this->generateUrl(self::NEW_ROUTE),
            'values' => $values,
            'errors' => $errors,
            // Opened from a contact the client is fixed; from the list it is chosen in the dialog.
            'client' => $client,
            'clientFixed' => $client instanceof PartySummary && ($request->isMethod('POST') ? $request->request->getBoolean('clientFixed') : true),
            'clientLinked' => false,
            ...$this->personsBlock($client, $person, $errors),
            ...$this->customBlock($custom),
            'owners' => $this->screen->owners(),
            'stages' => $this->stageOptions($stages),
        ], new Response(status: [] === $errors && (!$custom instanceof CustomFieldSubmission || $custom->valid()) ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/sales/deals/{id}', name: self::DEAL_ROUTE, requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $detail = $this->detail($id);
        $deal = $detail->deal;
        $return = $this->generateUrl(self::DEAL_ROUTE, ['id' => $id]);
        $context = ['dealId' => $deal->id(), 'clientId' => $deal->clientPartyId(), 'contactPersonIds' => $detail->contactPersonIds, 'return' => $return];
        $stages = $this->reads->stages();
        $sections = $deal->isTrashed() ? [] : $this->slotSections($context);
        // The tab asked for, or the first one, the timeline, when it names none of them.
        $keys = array_column($sections, 'key');
        $tab = $request->query->getString('tab');
        $tab = \in_array($tab, $keys, true) ? $tab : ($keys[0] ?? null);

        return $this->render('@Deals/deals/show.html.twig', [
            'deal' => $deal,
            'badge' => $deal->isTrashed() ? ['label' => $this->screen->trans('deals.deal.trashed'), 'tone' => 'gray'] : $this->screen->status($deal),
            'stageName' => $this->screen->stage($detail->stage),
            'stages' => array_map(fn (Stage $stage): array => ['id' => $stage->id(), 'name' => $this->screen->stage($stage), 'current' => $stage->id() === $deal->stageId()], $stages),
            'details' => $this->details($detail),
            'lines' => $this->lineRows($detail),
            'lineProjects' => [] !== array_filter($detail->lines, static fn (DealLine $line): bool => null !== $line->projectId()),
            'totals' => $this->totalRows($detail->value),
            'billing' => $this->billingSection($detail),
            'links' => $this->linkViews($detail),
            'linkable' => !$deal->isTrashed() && [] !== $this->linkOptions($detail),
            'slotActions' => $deal->isTrashed() ? [] : $this->slotActions($context),
            'tabs' => array_map(fn (array $section): array => [
                'label' => $section['label'],
                'href' => $this->generateUrl(self::DEAL_ROUTE, ['id' => $id, 'tab' => $section['key'] === ($keys[0] ?? null) ? null : $section['key']]),
                'active' => $section['key'] === $tab,
            ], $sections),
            'section' => array_values(array_filter($sections, static fn (array $section): bool => $section['key'] === $tab))[0] ?? null,
            'context' => $context,
        ]);
    }

    #[Route('/sales/deals/{id}/edit', name: 'app_sales_deal_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $detail = $this->detail($id);
        $deal = $detail->deal;
        // A link joins deals of one client, so a linked deal keeps its client.
        $linked = [] !== $detail->links;
        $client = $this->parties->find($deal->clientPartyId());
        $values = ['title' => $deal->title(), 'clientId' => $deal->clientPartyId(), 'clientQuery' => $client->displayName ?? '', 'ownerUserId' => $deal->ownerUserId(), 'description' => $deal->description(), 'contactPersonIds' => $detail->contactPersonIds];
        $person = $this->personValues();
        $errors = [];
        $custom = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = array_intersect_key($this->postedDeal($request), $values) + $values;
            if (!$linked) {
                $values['clientId'] = trim($request->request->getString('clientId'));
                $client = '' === $values['clientId'] ? null : $this->parties->find($values['clientId']);
            }
            $person = $this->personValues($request);
            $custom = $this->customFields->read(DealExtension::RECORD, $request, false);
            if ($custom->valid()) {
                try {
                    $this->writes->update(new UpdateDealInput($id, $values['title'], $values['description'], $values['ownerUserId'], $values['contactPersonIds'], $linked ? new Omitted() : $values['clientId']), $this->draft($person), $custom->parsed);
                    $this->addFlash('success', $this->screen->trans('deals.deal.flash.saved'));

                    return $this->redirectToRoute(self::DEAL_ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
                } catch (SalesRefused $refused) {
                    $this->denyIfForbidden($refused);
                    $errors = $this->errors($refused);
                } catch (ClientPersonRefused $refused) {
                    $errors = $this->personErrors($refused, $person);
                } catch (FieldRefused $refused) {
                    $custom = $custom->refused($refused->key, $this->screen->trans($refused->translationKey));
                }
            }
        }

        return $this->render('@Deals/deals/form.html.twig', [
            'deal' => $deal,
            'action' => $this->generateUrl('app_sales_deal_edit', ['id' => $id]),
            'values' => $values,
            'errors' => $errors,
            'client' => $client,
            'clientFixed' => $linked,
            'clientLinked' => $linked,
            ...$this->personsBlock($client, $person, $errors),
            ...$this->customBlock($custom),
            'owners' => $this->screen->owners($deal->ownerUserId()),
            'stages' => [],
        ], new Response(status: [] === $errors && (!$custom instanceof CustomFieldSubmission || $custom->valid()) ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/sales/deals/{id}/stage', name: 'app_sales_deal_stage', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function stage(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        if ($this->act(fn (): Deal => $this->writes->changeStage(new ChangeDealStageInput($id, $request->request->getString('stageId'))))) {
            $this->addFlash('success', $this->screen->trans('deals.deal.flash.stage_changed'));
        }

        return $this->back($id);
    }

    #[Route('/sales/deals/{id}/win', name: 'app_sales_deal_win', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function win(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        if ($this->act(fn (): Deal => $this->writes->win(new WinDealInput($id)))) {
            $this->addFlash('success', $this->screen->trans('deals.deal.flash.won'));
        }

        return $this->back($id);
    }

    /**
     * Marking a deal lost asks why, with a reason from the list and an optional note, as
     * Pipedrive's and Odoo's lost-reason prompts do; neither is required.
     */
    #[Route('/sales/deals/{id}/lose', name: 'app_sales_deal_lose', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function lose(string $id, Request $request): Response
    {
        $deal = $this->detail($id)->deal;
        $values = ['lostReasonId' => '', 'note' => ''];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = ['lostReasonId' => trim($request->request->getString('lostReasonId')), 'note' => trim($request->request->getString('note'))];
            try {
                $this->writes->lose(new LoseDealInput($id, '' === $values['lostReasonId'] ? null : $values['lostReasonId'], '' === $values['note'] ? null : $values['note']));
                $this->addFlash('success', $this->screen->trans('deals.deal.flash.lost'));

                return $this->redirectToRoute(self::DEAL_ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
            } catch (SalesRefused $refused) {
                $this->denyIfForbidden($refused);
                $field = $refused->field();
                $errors = [null !== $field && \in_array($field, ['lostReasonId', 'note'], true) ? $field : 'lostReasonId' => $this->screen->refusal($refused)];
            }
        }

        return $this->render('@Deals/deals/lose.html.twig', [
            'deal' => $deal,
            'values' => $values,
            'errors' => $errors,
            'reasons' => ['' => $this->screen->trans('deals.lose.no_reason')] + array_combine(array_map(static fn (LostReason $reason): string => $reason->id(), $this->reads->lostReasons()), array_map($this->screen->reason(...), $this->reads->lostReasons())),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/sales/deals/{id}/reopen', name: 'app_sales_deal_reopen', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function reopen(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        if ($this->act(fn (): Deal => $this->writes->reopen(new ReopenDealInput($id)))) {
            $this->addFlash('success', $this->screen->trans('deals.deal.flash.reopened'));
        }

        return $this->back($id);
    }

    /**
     * The deal goes to the trash, and the list says so with Undo, as the activities do.
     */
    #[Route('/sales/deals/{id}/trash', name: 'app_sales_deal_trash', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function trash(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $deal = $this->detail($id)->deal;
        if (!$this->act(fn (): Deal => $this->writes->trash(new TrashDealInput($id)))) {
            return $this->back($id);
        }
        $this->addFlash(self::UNDO_FLASH, [
            'message' => $this->screen->trans('deals.deal.flash.trashed', ['title' => $deal->title()]),
            'label' => $this->screen->trans('deals.deal.undo'),
            'href' => $this->generateUrl('app_sales_deal_restore', ['id' => $id]),
            'token' => self::TOKEN,
        ]);

        return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/sales/deals/{id}/restore', name: 'app_sales_deal_restore', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function restore(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        if ($this->act(fn (): Deal => $this->writes->restore(new RestoreDealInput($id)))) {
            $this->addFlash('success', $this->screen->trans('deals.deal.flash.restored'));
        }

        return $this->back($id);
    }

    /**
     * A follow-up deal in a dialog: its title and the lines of this deal to copy. It is
     * for the same client and contact persons and follows from this deal.
     */
    #[Route('/sales/deals/{id}/follow-up', name: 'app_sales_deal_follow_up', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function followUp(string $id, Request $request): Response
    {
        $detail = $this->detail($id);
        $values = ['title' => '', 'lineIds' => []];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = ['title' => trim($request->request->getString('title')), 'lineIds' => $this->strings($request->request->all('lineIds'))];
            try {
                $deal = $this->writes->createFollowUp(new CreateFollowUpDealInput($id, $values['title'], $values['lineIds']));
                $this->addFlash('success', $this->screen->trans('deals.deal.flash.created', ['title' => $deal->title()]));

                return $this->redirectToRoute(self::DEAL_ROUTE, ['id' => $deal->id()], Response::HTTP_SEE_OTHER);
            } catch (SalesRefused $refused) {
                $this->denyIfForbidden($refused);
                $errors = ['lineIds' === $refused->field() ? 'lineIds' : 'title' => $this->screen->refusal($refused)];
            }
        }

        return $this->render('@Deals/deals/follow_up.html.twig', [
            'deal' => $detail->deal,
            'values' => $values,
            'errors' => $errors,
            'lines' => array_map(fn (DealLine $line): array => ['id' => $line->id(), 'name' => $line->name(), 'text' => $this->screen->lineAmount($line).' · '.$this->screen->billing($line)], $detail->lines),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * A link to another deal of the same client, which this deal follows from, in a dialog.
     */
    #[Route('/sales/deals/{id}/links/new', name: 'app_sales_deal_link_new', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function newLink(string $id, Request $request): Response
    {
        $detail = $this->detail($id);
        $values = ['toDealId' => ''];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = ['toDealId' => trim($request->request->getString('toDealId'))];
            try {
                $this->links->add(new AddDealLinkInput($id, $values['toDealId']));
                $this->addFlash('success', $this->screen->trans('deals.link.flash.added'));

                return $this->back($id);
            } catch (SalesRefused $refused) {
                $this->denyIfForbidden($refused);
                $errors = ['toDealId' => $this->screen->refusal($refused)];
            }
        }

        return $this->render('@Deals/deals/link.html.twig', [
            'deal' => $detail->deal,
            'values' => $values,
            'errors' => $errors,
            'options' => $this->linkOptions($detail),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/sales/deals/{id}/links/{linkId}/remove', name: 'app_sales_deal_link_remove', requirements: ['id' => Requirement::UUID, 'linkId' => Requirement::UUID], methods: ['POST'])]
    public function removeLink(string $id, string $linkId, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        if ($this->act(fn (): Deal => $this->links->remove(new RemoveDealLinkInput($id, $linkId)))) {
            $this->addFlash('success', $this->screen->trans('deals.link.flash.removed'));
        }

        return $this->back($id);
    }

    /**
     * Ends the request when the deal cannot be read: 403 for a guest, 404 for an unknown deal.
     */
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
     * Runs a write of the deal's header; a refusal of its state, such as winning a deal
     * that is not open, is a message on the deal's page.
     *
     * @param \Closure(): Deal $write
     */
    private function act(\Closure $write): bool
    {
        try {
            $write();

            return true;
        } catch (SalesRefused $refused) {
            $this->denyIfForbidden($refused);
            if (RefusalKind::NotFound === $refused->kind()) {
                throw new NotFoundHttpException($refused->getMessage(), $refused);
            }
            $this->addFlash('error', $this->screen->refusal($refused));

            return false;
        }
    }

    private function back(string $id): RedirectResponse
    {
        return $this->redirectToRoute(self::DEAL_ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /**
     * The open deals of the view by stage, in the order of the stages: one section per
     * stage that holds a deal shown, as a list grouped by stage shows only the groups it
     * has (Odoo), with the count and the value of its deals. A closed deal keeps the stage
     * it was closed in, but it is no longer in the pipeline: the won deals and then the
     * lost ones follow in a section of their own, as Pipedrive takes them off the stages.
     *
     * @param list<Deal> $deals
     *
     * @return list<array<string, mixed>>
     */
    private function sections(array $deals, WorkingState $working): array
    {
        $byStage = [];
        $closed = [DealStatus::Won->value => [], DealStatus::Lost->value => []];
        foreach ($deals as $deal) {
            if ($deal->isOpen()) {
                $byStage[$deal->stageId()][] = $deal;
            } else {
                $closed[$deal->status()->value][] = $deal;
            }
        }
        $sections = [];
        foreach ($this->reads->stages(true) as $stage) {
            if (isset($byStage[$stage->id()])) {
                $sections[] = $this->section($stage->id(), $this->screen->stage($stage), $byStage[$stage->id()], $working);
                unset($byStage[$stage->id()]);
            }
        }
        foreach ($byStage as $stageId => $rest) {
            $sections[] = $this->section($stageId, $this->screen->stage(null), $rest, $working);
        }
        foreach ($closed as $status => $rest) {
            if ([] !== $rest) {
                $sections[] = $this->section($status, $this->screen->trans('deals.deals.tab.'.$status), $rest, $working);
            }
        }

        return $sections;
    }

    /**
     * @param list<Deal> $deals
     *
     * @return array<string, mixed>
     */
    private function section(string $id, string $title, array $deals, WorkingState $working): array
    {
        $rows = [];
        $values = [];
        foreach ($deals as $deal) {
            $value = DealValues::of($this->store->linesOf($deal->id()));
            $values[] = $value;
            $row = new DealRow($deal, $value);
            $rows[] = [
                'id' => $deal->id(),
                'href' => $this->generateUrl(self::DEAL_ROUTE, ['id' => $deal->id()]),
                // A deal is a page of its own, with its lines, links and sections.
                'opens' => Opens::PAGE,
                'label' => $deal->title(),
                'secondary' => implode(' · ', array_filter([$this->screen->client($deal->clientPartyId()), $this->screen->value($value)], static fn (string $part): bool => '' !== $part)),
                'cells' => $working->display->cells($row),
            ];
        }
        $total = $this->screen->value($this->sum($values));

        return [
            'id' => $id,
            'title' => $title,
            'rows' => $rows,
            'count' => $this->screen->trans('deals.deals.count', ['count' => \count($rows)]),
            'total' => '' === $total ? null : $total,
        ];
    }

    /**
     * The values of several deals added up, one-off and monthly apart, per currency.
     *
     * @param list<DealValue> $values
     */
    private function sum(array $values): DealValue
    {
        $oneOff = [];
        $monthly = [];
        $approximate = false;
        foreach ($values as $value) {
            $oneOff = $this->added($oneOff, $value->oneOff);
            $monthly = $this->added($monthly, $value->monthly);
            $approximate = $approximate || ([] !== $value->monthly && $value->approximate);
        }
        ksort($oneOff);
        ksort($monthly);

        return new DealValue($oneOff, $monthly, $approximate);
    }

    /**
     * @param array<string, Money> $into
     * @param array<string, Money> $amounts
     *
     * @return array<string, Money>
     */
    private function added(array $into, array $amounts): array
    {
        foreach ($amounts as $currency => $money) {
            $into[$currency] = isset($into[$currency]) ? $into[$currency]->plus($money) : $money;
        }

        return $into;
    }

    /**
     * @return list<array{label: string, value: mixed, empty?: string}>
     */
    private function details(DealDetail $detail): array
    {
        $deal = $detail->deal;
        $persons = array_map(static fn (PartyPersonSummary $person): string => $person->displayName, $detail->contactPersons);
        $client = $this->screen->client($deal->clientPartyId());
        $clientPage = $this->contactPages?->urlFor($deal->clientPartyId(), ['tab' => 'deals']);
        $details = [
            ['label' => $this->screen->trans('deals.deal.field.client'), 'value' => null === $clientPage ? $client : ['links' => [['text' => $client, 'href' => $clientPage]]]],
            ['label' => $this->screen->trans('deals.deal.field.contact_persons'), 'value' => [] === $persons ? null : implode(', ', $persons), 'empty' => $this->screen->trans('deals.deal.none.contact_persons')],
            ['label' => $this->screen->trans('deals.deal.field.owner'), 'value' => $this->screen->owner($deal->ownerUserId())],
        ];
        $date = $this->screen->trans('format.date');
        // The stage while the deal is open; once it is closed, the day it was won or lost.
        if ($deal->isOpen()) {
            $details[] = ['label' => $this->screen->trans('deals.deal.field.stage'), 'value' => $this->screen->stage($detail->stage)];
        }
        $won = $deal->wonAt();
        if ($won instanceof \DateTimeImmutable) {
            $details[] = ['label' => $this->screen->trans('deals.deal.field.won_at'), 'value' => $won->format($date)];
        }
        $reason = $detail->lostReason;
        $lost = $deal->lostAt();
        if ($lost instanceof \DateTimeImmutable) {
            $details[] = ['label' => $this->screen->trans('deals.deal.field.lost_at'), 'value' => $lost->format($date)];
            $details[] = ['label' => $this->screen->trans('deals.deal.field.lost_reason'), 'value' => $reason instanceof LostReason ? $this->screen->reason($reason) : null, 'empty' => $this->screen->trans('deals.deal.none.lost_reason')];
            $details[] = ['label' => $this->screen->trans('deals.deal.field.lost_note'), 'value' => $deal->lostNote(), 'empty' => $this->screen->trans('deals.deal.none.lost_note')];
        }
        $details[] = ['label' => $this->screen->trans('deals.deal.field.created_at'), 'value' => $deal->createdAt()->format($date)];

        return $details;
    }

    /**
     * The lines of the deal as the rows of a table, with Edit, Up, Down and Remove.
     *
     * @return list<array<string, mixed>>
     */
    private function lineRows(DealDetail $detail): array
    {
        $deal = $detail->deal;
        $last = \count($detail->lines) - 1;
        $rows = [];
        foreach ($detail->lines as $index => $line) {
            $edit = $this->generateUrl('app_sales_deal_line_edit', ['id' => $deal->id(), 'lineId' => $line->id()]);
            $price = $line->unitPrice();
            $actions = [];
            if (!$deal->isTrashed()) {
                $actions[] = ['label' => $this->screen->trans('deals.line.edit'), 'href' => $edit, 'icon' => 'pencil', 'dialog' => true];
                if ($index > 0) {
                    $actions[] = ['label' => $this->screen->trans('deals.line.move_up'), 'href' => $this->generateUrl('app_sales_deal_line_move', ['id' => $deal->id(), 'lineId' => $line->id()]), 'method' => 'POST', 'fields' => ['direction' => 'up']];
                }
                if ($index < $last) {
                    $actions[] = ['label' => $this->screen->trans('deals.line.move_down'), 'href' => $this->generateUrl('app_sales_deal_line_move', ['id' => $deal->id(), 'lineId' => $line->id()]), 'method' => 'POST', 'fields' => ['direction' => 'down']];
                }
                $actions[] = ['label' => $this->screen->trans('deals.line.remove'), 'href' => $this->generateUrl('app_sales_deal_line_remove', ['id' => $deal->id(), 'lineId' => $line->id()]), 'method' => 'POST',
                    'confirm' => ['title' => $this->screen->trans('deals.line.confirm.remove', ['name' => $line->name()]), 'accept' => $this->screen->trans('deals.line.remove'), 'cancel' => $this->screen->trans('deals.keep'), 'tone' => 'danger']];
            }
            $rows[] = [
                'id' => $line->id(),
                'href' => $deal->isTrashed() ? null : $edit,
                'opens' => Opens::DIALOG,
                'label' => $line->name(),
                'secondary' => $this->screen->lineAmount($line),
                'cells' => [
                    'name' => $line->name(),
                    'quantity' => $this->screen->lineAmount($line),
                    'billing' => $this->screen->billing($line).$this->logged($line),
                    'project' => $this->projectCell($line),
                    'total' => $this->screen->lineTotal($line),
                ],
                'actions' => $actions,
            ];
        }

        return $rows;
    }

    /**
     * The billing plan section: shown for a won deal, where it changes, and for
     * an open or lost one that has a plan, read-only with a note that it is not
     * counted; null for a deal with nothing to show. Each row says what it is,
     * its amount, its day or "No date", and whether it is planned, overdue or
     * invoiced, with a link to its document.
     *
     * @return array{editable: bool, note: ?string, rows: list<array<string, mixed>>, notPlanned: string, notInvoiced: string}|null
     */
    private function billingSection(DealDetail $detail): ?array
    {
        $deal = $detail->deal;
        $won = DealStatus::Won === $deal->status();
        $plan = $detail->billingPlan;
        if (!$won && [] === $plan->entries && !$deal->isOpen()) {
            return null;
        }
        $editable = $won && !$deal->isTrashed();
        $rows = [];
        foreach ($plan->entries as $view) {
            $entry = $view->entry;
            $actions = [];
            $edit = $this->generateUrl('app_sales_billing_entry_edit', ['id' => $deal->id(), 'entryId' => $entry->id()]);
            if ($editable) {
                $actions[] = ['label' => $this->screen->trans('deals.billing.action.edit'), 'href' => $edit, 'icon' => 'pencil', 'dialog' => true];
                if ($entry->isFulfilled()) {
                    $actions[] = ['label' => $this->screen->trans('deals.billing.action.unlink'), 'href' => $this->generateUrl('app_sales_billing_entry_unlink', ['id' => $deal->id(), 'entryId' => $entry->id()]), 'icon' => 'x', 'method' => 'POST'];
                } else {
                    $actions[] = ['label' => $this->screen->trans('deals.billing.action.link'), 'href' => $this->generateUrl('app_sales_billing_entry_link', ['id' => $deal->id(), 'entryId' => $entry->id()]), 'icon' => 'link-2', 'dialog' => true];
                }
                $actions[] = ['label' => $this->screen->trans('deals.billing.action.delete'), 'href' => $this->generateUrl('app_sales_billing_entry_delete', ['id' => $deal->id(), 'entryId' => $entry->id()]), 'method' => 'POST',
                    'confirm' => ['title' => $this->screen->trans('deals.billing.confirm.delete'), 'accept' => $this->screen->trans('deals.billing.action.delete'), 'cancel' => $this->screen->trans('deals.keep'), 'tone' => 'danger']];
            }
            $label = '' !== $entry->text() ? $entry->text() : ($view->line?->name() ?? $this->screen->trans('deals.billing.kind.'.$entry->kind()->value));
            $on = $entry->expectedOn();
            $rows[] = [
                'id' => $entry->id(),
                'href' => $editable ? $edit : null,
                'opens' => Opens::DIALOG,
                'label' => $label,
                'secondary' => $view->amount instanceof Money ? $this->screen->money($view->amount) : '',
                'cells' => [
                    'text' => $label,
                    'kind' => $this->screen->trans('deals.billing.kind.'.$entry->kind()->value).(BillingEntryKind::Recurring === $entry->kind() && $view->line instanceof DealLine ? ' · '.$this->screen->billing($view->line) : ''),
                    'expected' => $on instanceof \DateTimeImmutable ? ['text' => $this->screen->day($on)] : ['text' => '', 'empty' => $this->screen->trans('deals.billing.no_date')],
                    'state' => $this->billingState($view),
                    'amount' => $view->amount instanceof Money ? $this->screen->money($view->amount) : '',
                ],
                'actions' => $actions,
            ];
        }
        $note = match (true) {
            $deal->isTrashed() => null,
            $deal->isOpen() && [] === $plan->entries => $this->screen->trans('deals.billing.note.open'),
            !$won => $this->screen->trans('deals.billing.note.not_won'),
            default => null,
        };

        return [
            'editable' => $editable,
            'note' => $note,
            'rows' => $rows,
            'notPlanned' => $won ? $this->screen->amounts($plan->notPlanned) : '',
            'notInvoiced' => $this->screen->amounts($plan->notInvoiced()),
        ];
    }

    /**
     * Planned, overdue, or invoiced with a link to the document.
     *
     * @return array<string, mixed>
     */
    private function billingState(BillingEntryView $view): array
    {
        $document = $view->document;
        if (BillingEntryView::INVOICED === $view->state && $document instanceof BillingDocument && null !== $document->url) {
            return ['links' => [['text' => $document->label, 'href' => $document->url, 'opens' => Opens::PAGE]], 'plain' => true];
        }

        return match ($view->state) {
            BillingEntryView::INVOICED => ['text' => $this->screen->trans('deals.billing.state.invoiced'), 'tone' => 'green'],
            BillingEntryView::OVERDUE => ['text' => $this->screen->trans('deals.billing.state.overdue'), 'tone' => 'amber'],
            default => ['text' => $this->screen->trans('deals.billing.state.planned'), 'tone' => 'gray'],
        };
    }

    /**
     * The project of an hourly line, a link to its page; a project the person
     * does not see is named as such, without a link.
     *
     * @return array<string, mixed>|string
     */
    private function projectCell(DealLine $line): array|string
    {
        $id = $line->projectId();
        if (null === $id) {
            return '';
        }
        $project = $this->lineProjects->find($id);

        return $project instanceof LineProject
            ? ['links' => [['text' => $project->name, 'href' => $project->url, 'opens' => Opens::PAGE]], 'plain' => true]
            : $this->screen->trans('deals.line.project_hidden');
    }

    /**
     * " · 2.5 h billable logged" after the billing of a line linked to a project;
     * nothing for a project the person does not see.
     */
    private function logged(DealLine $line): string
    {
        $id = $line->projectId();
        $minutes = null === $id ? null : $this->lineProjects->billableMinutes($id);
        if (null === $minutes) {
            return '';
        }

        return ' · '.$this->screen->trans('deals.line.logged', ['hours' => $this->screen->quantity((string) round($minutes / 60, 2))]);
    }

    /**
     * The footer rows of the lines table: one-off, monthly and hourly apart, each under the
     * Amount column with its currencies side by side, a kind with no priced line left
     * out, "≈" when the monthly value is approximate.
     *
     * @return list<array{name: string, total: string}>
     */
    private function totalRows(DealValue $value): array
    {
        $rows = [];
        if ([] !== $value->oneOff) {
            $rows[] = ['name' => $this->screen->trans('deals.value.total_one_off'), 'total' => $this->amounts($value->oneOff)];
        }
        if ([] !== $value->monthly) {
            $rows[] = ['name' => $this->screen->trans('deals.value.total_monthly'), 'total' => ($value->approximate ? '≈ ' : '').$this->amounts($value->monthly)];
        }
        if ([] !== $value->hourly) {
            // Never added into the other two: the hours are not worked yet.
            $rows[] = ['name' => $this->screen->trans('deals.value.total_hourly'), 'total' => $this->screen->hourly($value->hourly)];
        }

        return $rows;
    }

    /**
     * @param array<string, Money> $amounts
     */
    private function amounts(array $amounts): string
    {
        return implode(' · ', array_map($this->screen->money(...), array_values($amounts)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linkViews(DealDetail $detail): array
    {
        $views = [];
        foreach ($detail->links as $view) {
            $views[] = [
                'id' => $view->link->id(),
                'title' => $view->other->title(),
                'href' => $this->generateUrl(self::DEAL_ROUTE, ['id' => $view->other->id()]),
                'relation' => $this->screen->trans($view->outgoing ? 'deals.link.follows_from' : 'deals.link.followed_by'),
                'status' => $this->screen->status($view->other),
                'remove' => $detail->deal->isTrashed() ? null : $this->generateUrl('app_sales_deal_link_remove', ['id' => $detail->deal->id(), 'linkId' => $view->link->id()]),
            ];
        }

        return $views;
    }

    /**
     * The other deals of the client this deal may follow from: not itself, not one linked already.
     *
     * @return array<string, string> titles by id
     */
    private function linkOptions(DealDetail $detail): array
    {
        $linked = array_map(static fn (DealLinkView $view): string => $view->other->id(), $detail->links);
        $options = [];
        foreach ($this->reads->dealsOfClient($detail->deal->clientPartyId()) as $deal) {
            if ($deal->id() !== $detail->deal->id() && !\in_array($deal->id(), $linked, true)) {
                $options[$deal->id()] = $deal->title();
            }
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<array{label: string, href: string, icon: ?string, dialog: bool, key: string, prominent: bool}>
     */
    private function slotActions(array $context): array
    {
        $actions = [];
        foreach ($this->slots->items(SalesSlots::DEAL_ACTIONS, $context) as $item) {
            $label = $item->data['label'] ?? null;
            $href = $item->data['href'] ?? null;
            $icon = $item->data['icon'] ?? null;
            if (!\is_string($label) || '' === $label || !\is_string($href) || null === DealScreen::localPath($href)) {
                continue;
            }
            try {
                Guard::icon(\is_string($icon) ? $icon : null);
            } catch (\InvalidArgumentException) {
                $icon = null;
            }
            $actions[] = ['label' => $label, 'href' => $href, 'icon' => \is_string($icon) ? $icon : null, 'dialog' => true === ($item->data['dialog'] ?? false), 'key' => \is_string($item->data['key'] ?? null) ? $item->data['key'] : '', 'prominent' => true === ($item->data['prominent'] ?? false)];
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<array{key: string, label: string, href: ?string, template: ?string, data: array<string, mixed>}>
     */
    private function slotSections(array $context): array
    {
        $sections = [];
        foreach ($this->slots->items(SalesSlots::DEAL_SECTIONS, $context) as $item) {
            $key = $item->data['key'] ?? null;
            $label = $item->data['label'] ?? null;
            $href = $item->data['href'] ?? null;
            if (!\is_string($key) || 1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $key) || !\is_string($label) || '' === $label) {
                continue;
            }
            $href = \is_string($href) ? DealScreen::localPath($href) : null;
            if (null === $href && null === $item->template) {
                continue;
            }
            $sections[] = ['key' => $key, 'label' => $label, 'href' => $href, 'template' => $item->template, 'data' => $item->data + $context];
        }

        return $sections;
    }

    /**
     * @return list<array{id: string, name: string}> the contact persons of the client
     */
    private function persons(?string $clientId): array
    {
        if (null === $clientId) {
            return [];
        }

        return array_map(static fn (PartyPersonSummary $person): array => ['id' => $person->id, 'name' => $person->displayName], $this->parties->personsOf($clientId));
    }

    /**
     * What the contact persons block of the dialog shows for the client: its persons to
     * tick and the person to add, from the address book (while it has one to offer) or
     * new. A person has no contact persons, so the block is for a company or sole trader.
     *
     * @param array{mode: string, personId: string, name: string, email: string, position: string} $person
     * @param array<string, string>                                                                $errors
     *
     * @return array{personsShown: bool, persons: list<array{id: string, name: string}>, candidates: array<string, string>, person: array{mode: string, personId: string, name: string, email: string, position: string}, personModes: list<array{value: string, label: string}>, personOpen: bool}
     */
    private function personsBlock(?PartySummary $client, array $person, array $errors): array
    {
        $shown = null !== $this->clientPersons && $client instanceof PartySummary && ContactKind::Person !== $client->kind;
        $candidates = [];
        foreach ($shown ? $this->clientPersons->candidatesOf($client->id) : [] as $candidate) {
            $candidates[$candidate->id] = $candidate->displayName;
        }
        if ('' === $person['mode']) {
            // From the address book first while it has a person to offer, else a new one (as the contact's own dialog).
            $person['mode'] = [] === $candidates ? self::PERSON_NEW : self::PERSON_EXISTING;
        }
        $typed = '' !== $person['personId'] || '' !== $person['name'] || '' !== $person['email'] || '' !== $person['position'];

        return [
            'personsShown' => $shown,
            'persons' => $shown ? $this->persons($client->id) : [],
            'candidates' => $candidates,
            'person' => $person,
            'personModes' => [
                ['value' => self::PERSON_EXISTING, 'label' => $this->screen->trans('deals.deal.person.mode.existing')],
                ['value' => self::PERSON_NEW, 'label' => $this->screen->trans('deals.deal.person.mode.new')],
            ],
            'personOpen' => $typed || [] !== array_intersect_key($errors, array_flip(['personId', 'personName', 'personEmail', 'personPosition'])),
        ];
    }

    /**
     * The person to add as the dialog sent it; nothing on a GET.
     *
     * @return array{mode: string, personId: string, name: string, email: string, position: string}
     */
    private function personValues(?Request $request = null): array
    {
        $posted = $request?->request;
        $mode = $posted?->getString('personMode') ?? '';

        return [
            'mode' => \in_array($mode, [self::PERSON_EXISTING, self::PERSON_NEW], true) ? $mode : '',
            'personId' => trim($posted?->getString('personId') ?? ''),
            'name' => trim($posted?->getString('personName') ?? ''),
            'email' => trim($posted?->getString('personEmail') ?? ''),
            'position' => trim($posted?->getString('personPosition') ?? ''),
        ];
    }

    /**
     * The person to add, by the way chosen; none when its fields are empty. Without
     * JavaScript both ways show, and the one not chosen is ignored.
     *
     * @param array{mode: string, personId: string, name: string, email: string, position: string} $person
     */
    private function draft(array $person): ?ContactPersonDraft
    {
        if (self::PERSON_EXISTING === $person['mode']) {
            return '' === $person['personId'] ? null : ContactPersonDraft::existing($person['personId'], $person['position']);
        }
        if (self::PERSON_NEW === $person['mode'] && ('' !== $person['name'] || '' !== $person['email'] || '' !== $person['position'])) {
            return ContactPersonDraft::new($person['name'], $person['email'], $person['position']);
        }

        return null;
    }

    /**
     * @param array{mode: string, personId: string, name: string, email: string, position: string} $person
     *
     * @return array<string, string> the message of each refused field of the person, by form field
     */
    private function personErrors(ClientPersonRefused $refused, array $person): array
    {
        $errors = [];
        foreach ($refused->reasons as $field => $reason) {
            $key = match ($field) {
                'name' => 'personName',
                'email' => 'personEmail',
                'position' => 'personPosition',
                default => self::PERSON_NEW === $person['mode'] ? 'personName' : 'personId',
            };
            $errors[$key] = $this->screen->trans('deals.deal.person.error.'.$reason);
        }

        return $errors;
    }

    /**
     * What the custom fields of the form show: what was posted, or the deal's own values (null).
     *
     * @return array{customValues: array<string, string|list<string>>|null, customErrors: array<string, string>}
     */
    private function customBlock(?CustomFieldSubmission $custom): array
    {
        return ['customValues' => $custom?->values, 'customErrors' => $custom->errors ?? []];
    }

    /**
     * @param list<Stage> $stages
     *
     * @return array<string, string>
     */
    private function stageOptions(array $stages): array
    {
        return array_combine(array_map(static fn (Stage $stage): string => $stage->id(), $stages), array_map($this->screen->stage(...), $stages));
    }

    /**
     * @return array{title: string, ownerUserId: string, stageId: string, description: string, contactPersonIds: list<string>, clientQuery: string}
     */
    private function postedDeal(Request $request): array
    {
        $posted = $request->request;

        return [
            'title' => trim($posted->getString('title')),
            'ownerUserId' => trim($posted->getString('ownerUserId')),
            'stageId' => trim($posted->getString('stageId')),
            'description' => $posted->getString('description'),
            'contactPersonIds' => $this->strings($posted->all('contactPersonIds')),
            'clientQuery' => trim($posted->getString('clientQuery')),
        ];
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_filter($values, static fn (mixed $value): bool => \is_string($value) && '' !== $value));
    }

    /**
     * @return array<string, string> the message of the refused field, by form field
     */
    private function errors(SalesRefused $refused): array
    {
        $field = $refused->field();
        $field = match (true) {
            'clientPartyId' === $field, 'client_archived' === $refused->reason() => 'clientId',
            null !== $field && \in_array($field, ['title', 'description', 'ownerUserId', 'stageId', 'contactPersonIds'], true) => $field,
            default => 'title',
        };

        return [$field => $this->screen->refusal($refused)];
    }

    /**
     * @return array<string, array{url: string, name: \Closure(string): ?string}>
     */
    private function references(): array
    {
        return [
            DealLists::STAGE_SOURCE => ['url' => $this->generateUrl('app_sales_deal_stages'), 'name' => fn (string $stage): ?string => ($found = $this->store->stage($stage)) instanceof Stage ? $this->screen->stage($found) : null],
            DealLists::MEMBER => ['url' => $this->generateUrl('app_sales_deal_owners'), 'name' => $this->screen->owner(...)],
            DealLists::CONTACT => ['url' => $this->generateUrl('app_sales_deal_clients'), 'name' => fn (string $contact): ?string => $this->parties->find($contact)?->displayName],
        ];
    }

    /**
     * @param InputBag<string> $input
     *
     * @return array{list<ListView>, ListView, ViewFilter}
     */
    private function views(InputBag $input): array
    {
        $list = $this->dealLists->list();
        $views = $this->listViews->of($list, $this->actorRole->userId());
        $view = ListViews::chosen($views, $input->getString('view'));

        return [$views, $view, WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter];
    }

    /**
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function builtQuery(InputBag $input): array
    {
        [, $view] = $this->views($input);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'view' => true]);

        return array_filter($query + ['view' => $view->isAll() ? null : $view->key, WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $this->dealLists->list()), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * @param list<ListView>        $views
     * @param array<string, string> $query
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    private function tabs(array $views, string $active, array $query, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($views);
        $link = fn (ListView $view): array => [
            'label' => $view->translated ? $this->screen->trans($view->name) : $view->name,
            'href' => $this->generateUrl(self::ROUTE, ['view' => $view->isAll() ? null : $view->key] + array_diff_key($query, ['view' => true, 'sort' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
            'active' => $view->key === $active,
            'modified' => $modified && $view->key === $active,
            'private' => $view->private,
        ];

        return ['tabs' => array_map($link, $primary), 'moreViews' => array_map($link, $overflow)];
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function picker(WorkingState $working, array $query): array
    {
        $kept = array_diff_key($query, array_flip(WorkingDisplay::KEYS));

        return [
            'keep' => $kept,
            'columns' => $working->picker(),
            'resetHref' => $this->generateUrl(self::ROUTE, $kept + [WorkingDisplay::WIDTHS => '']),
            'modified' => $working->modified,
        ];
    }

    /**
     * @return string the id of the person looking
     */
    private function denyUnlessStaff(): string
    {
        try {
            return $this->access->denyUnlessStaff();
        } catch (SalesRefused) {
            throw new AccessDeniedHttpException('Only the owners and members of the organisation work with its deals.');
        }
    }

    private function denyIfForbidden(SalesRefused $refused): void
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
