<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Controller;

use Foresi\Deals\Application\Api\ArchiveDealStageInput;
use Foresi\Deals\Application\Api\ArchiveLostReasonInput;
use Foresi\Deals\Application\Api\CreateDealStageInput;
use Foresi\Deals\Application\Api\CreateLostReasonInput;
use Foresi\Deals\Application\Api\ReorderDealStagesInput;
use Foresi\Deals\Application\Api\UpdateDealStageInput;
use Foresi\Deals\Application\Api\UpdateLostReasonInput;
use Foresi\Deals\Application\LostReasonWrites;
use Foresi\Deals\Application\SalesAccess;
use Foresi\Deals\Application\SalesRefused;
use Foresi\Deals\Application\SalesStore;
use Foresi\Deals\Application\StageWrites;
use Foresi\Deals\Application\View\DealStageLists;
use Foresi\Deals\Application\View\LostReasonLists;
use Foresi\Deals\Domain\LostReason;
use Foresi\Deals\Domain\Stage;
use Foresi\Deals\Infrastructure\Column\DealStageColumns;
use Foresi\Deals\Infrastructure\Column\LostReasonColumns;
use Foresi\Deals\Infrastructure\Screen\DealScreen;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Shared\Application\View\ListSlot;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\RowConditions;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\ViewState;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ViewBar;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Settings → Sales: the stages of a deal in their order and the reasons a
 * deal is lost, as Pipedrive lets a team rename, add and order its stages and
 * keep predefined lost reasons. A stage or reason is a small record: a new one
 * and a rename open in a dialog, Up and Down move a stage and archiving asks
 * first. A stage that still holds open deals is not archived: the refusal says
 * so, where Pipedrive would delete its deals with it. Each of the two tables is a
 * list with views, columns and widths of its own (list-pages.md, rule 8):
 * the stages on the address's bare keys, the reasons under their list slot.
 */
final class SalesSettingsController extends AbstractController
{
    public const string ROUTE = 'app_deal_settings';
    /** The slot of the lost reasons in the page's address (ListSlot). */
    public const string REASONS = 'reasons';
    private const int MAX_SEARCH = 100;

    public function __construct(
        private readonly SalesAccess $access,
        private readonly SalesStore $store,
        private readonly StageWrites $stages,
        private readonly LostReasonWrites $reasons,
        private readonly DealScreen $screen,
        private readonly DealStageLists $stageLists,
        private readonly LostReasonLists $reasonLists,
        private readonly ListViews $listViews,
        private readonly ActorRole $actorRole,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/settings/deals', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        $page = $request->query->all();
        // Two tables on one page (list-pages.md, rule 8): the stages keep the bare keys, the reasons theirs under a slot.
        $slots = [ListSlot::first($page, self::REASONS), ListSlot::named(self::REASONS, $page)];
        $lists = [$this->stageLists->list(), $this->reasonLists->list()];
        foreach ($slots as $at => $slot) {
            // The builder's rows (spec D11) become the table's working filter, in its part of the address.
            if ($slot->input()->has(FilterRows::MARKER)) {
                return $this->redirectToRoute(self::ROUTE, $slot->with($this->builtQuery($slot, $lists[$at])), Response::HTTP_SEE_OTHER);
            }
        }
        $stages = $this->store->stages(true);
        $open = array_values(array_filter($stages, static fn (Stage $stage): bool => !$stage->isArchived()));

        return $this->render('@Deals/settings/index.html.twig', [
            'stages' => $this->table($slots[0], $lists[0], DealStageColumns::LIST, 'sales-stage', $stages, fn (Stage $stage, WorkingState $working): array => $this->stageRow($stage, $open, $working)),
            'reasons' => $this->table($slots[1], $lists[1], LostReasonColumns::LIST, 'sales-reason', $this->store->lostReasons(true), $this->reasonRow(...)),
        ]);
    }

    #[Route('/settings/deals/stages/new', name: 'app_deal_stage_new', methods: ['GET', 'POST'])]
    public function newStage(Request $request): Response
    {
        $this->denyUnlessStaff();

        return $this->form($request, 'stage', null, null, ['name' => '']);
    }

    #[Route('/settings/deals/stages/{id}/edit', name: 'app_deal_stage_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function editStage(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $stage = $this->store->stage($id) ?? throw new NotFoundHttpException();

        return $this->form($request, 'stage', $stage, $stage->name(), ['name' => $this->screen->stage($stage)]);
    }

    /**
     * Moves a stage one place up or down among the stages not archived.
     */
    #[Route('/settings/deals/stages/{id}/move', name: 'app_deal_stage_move', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function moveStage(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $ids = array_map(static fn (Stage $stage): string => $stage->id(), $this->store->stages());
        $at = array_search($id, $ids, true);
        if (false === $at) {
            throw new NotFoundHttpException();
        }
        $to = 'up' === $request->request->getString('direction') ? $at - 1 : $at + 1;
        if ($to >= 0 && $to < \count($ids)) {
            [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
            $this->act(fn (): mixed => $this->stages->reorder(new ReorderDealStagesInput(array_values($ids))), null);
        }

        return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/settings/deals/stages/{id}/archive', name: 'app_deal_stage_archive', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function archiveStage(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $stage = $this->store->stage($id) ?? throw new NotFoundHttpException();
        $this->act(fn (): mixed => $this->stages->archive(new ArchiveDealStageInput($id)), $this->screen->trans('deals.settings.flash.archived', ['name' => $this->screen->stage($stage)]));

        return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/settings/deals/lost-reasons/new', name: 'app_deal_lost_reason_new', methods: ['GET', 'POST'])]
    public function newReason(Request $request): Response
    {
        $this->denyUnlessStaff();

        return $this->form($request, 'reason', null, null, ['name' => '']);
    }

    #[Route('/settings/deals/lost-reasons/{id}/edit', name: 'app_deal_lost_reason_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function editReason(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $reason = $this->store->lostReason($id) ?? throw new NotFoundHttpException();

        return $this->form($request, 'reason', $reason, $reason->name(), ['name' => $this->screen->reason($reason)]);
    }

    #[Route('/settings/deals/lost-reasons/{id}/archive', name: 'app_deal_lost_reason_archive', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function archiveReason(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $reason = $this->store->lostReason($id) ?? throw new NotFoundHttpException();
        $this->act(fn (): mixed => $this->reasons->archive(new ArchiveLostReasonInput($id)), $this->screen->trans('deals.settings.flash.archived', ['name' => $this->screen->reason($reason)]));

        return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    /**
     * A new stage or lost reason, or a rename of one. A seeded one keeps its translated
     * name until someone gives it another.
     *
     * @param 'stage'|'reason'      $kind
     * @param array<string, string> $values
     */
    private function form(Request $request, string $kind, Stage|LostReason|null $record, ?string $ownName, array $values): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values['name'] = trim($request->request->getString('name'));
            $name = null === $ownName && null !== $record && $values['name'] === $this->name($record) ? null : $values['name'];
            try {
                match (true) {
                    $record instanceof Stage => $this->stages->update(new UpdateDealStageInput($record->id(), $name)),
                    $record instanceof LostReason => $this->reasons->update(new UpdateLostReasonInput($record->id(), $name)),
                    'stage' === $kind => $this->stages->create(new CreateDealStageInput($values['name'])),
                    default => $this->reasons->create(new CreateLostReasonInput($values['name'])),
                };
                $this->addFlash('success', $this->screen->trans('deals.settings.flash.saved', ['name' => $values['name']]));

                return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
            } catch (SalesRefused $refused) {
                $errors = ['name' => $this->screen->refusal($refused)];
            }
        }
        $routes = 'stage' === $kind ? ['app_deal_stage_edit', 'app_deal_stage_new'] : ['app_deal_lost_reason_edit', 'app_deal_lost_reason_new'];

        return $this->render('@Deals/settings/record.html.twig', [
            'kind' => $kind,
            'record' => $record,
            'values' => $values,
            'errors' => $errors,
            'action' => null === $record ? $this->generateUrl($routes[1]) : $this->generateUrl($routes[0], ['id' => $record->id()]),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * One table of the page as every list is (docs/design/list-pages.md): its views, the
     * filter sheet, the search, its Settings sheet with columns, and widths a
     * person drags, all of it kept in its slot of the address so the other table keeps its own.
     *
     * @template T of Stage|LostReason
     *
     * @param list<T>                                         $records
     * @param \Closure(T, WorkingState): array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function table(ListSlot $slot, ListDefinition $list, string $columns, string $id, array $records, \Closure $row): array
    {
        $input = $slot->input();
        $views = $this->listViews->of($list, $this->actorRole->userId());
        $view = ListViews::chosen($views, $input->getString('view'));
        $shown = WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter;
        $filterParam = WorkingFilter::param($shown, $view->filter);
        $text = trim(mb_substr($input->getString('q'), 0, self::MAX_SEARCH));
        // The order of the page is the records' own (a stage's place in the pipeline): no column sorts.
        $sort = ViewState::sortParam($view->sort);
        $query = array_filter(['view' => $view->isAll() ? null : $view->key, 'q' => '' === $text ? null : $text, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value);
        $working = $this->rememberedDisplay->read($columns, [], $list->key, $view, $slot->query(), filters: $list);
        $query += $working->query;
        $url = fn (array $own): string => $this->generateUrl(self::ROUTE, $slot->with($own));
        $bar = $this->viewBar->of($list, $views, $view, $shown, $sort, $sort, $working, self::ROUTE, '' === $text ? [] : ['q' => $text], $slot);
        $kept = array_diff_key($query, array_flip(WorkingDisplay::KEYS));
        $shownRecords = RowConditions::filter($records, $shown, $list, $this->valueOf(...), new \DateTimeImmutable('today'));
        if ('' !== $text) {
            $shownRecords = array_values(array_filter($shownRecords, fn (Stage|LostReason $record): bool => false !== mb_stripos($this->name($record), $text)));
        }
        [$primary, $overflow] = ListViews::placed($views);
        $tab = fn (ListView $one): array => [
            'label' => $one->translated ? $this->screen->trans($one->name) : $one->name,
            // A view opens with its own filter and columns (spec D10).
            'href' => $url(['view' => $one->isAll() ? null : $one->key] + array_diff_key($query, ['view' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
            'active' => $one->key === $view->key,
            'modified' => $bar['viewBar']['modified'] && $one->key === $view->key,
            'private' => $one->private,
        ];

        return [
            'id' => $id,
            'view' => $view->isAll() ? '' : $view->key,
            'search' => ['name' => $slot->field('q'), 'value' => $text, 'label' => $this->screen->trans('deals.settings.search')],
            // What the filter bar's forms carry: this table's own state under its names, the other table's as it is.
            'keep' => $this->fields($slot, array_diff_key($query, ['q' => true])),
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $url([WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true])), [], pinned: $working->pinned, slot: $slot),
            ...$bar,
            'tabs' => array_map($tab, $primary),
            'moreViews' => array_map($tab, $overflow),
            'columns' => $working->display->table($sort, fn (string $next): string => $url(['sort' => $next] + $query)),
            'rows' => array_map(static fn (Stage|LostReason $record): array => $row($record, $working), $shownRecords),
            'widthsParam' => $slot->field(WorkingDisplay::WIDTHS),
            'picker' => [
                'keep' => $kept,
                'columns' => $working->picker(),
                // Empty widths name the view's own display, which forgets the one remembered (D-display-3).
                'resetHref' => $url($kept + [WorkingDisplay::WIDTHS => '']),
                'modified' => $working->modified,
                'slot' => $slot,
            ],
            'filtered' => '' !== $text || null !== $filterParam,
            'resetHref' => $url(['view' => $view->isAll() ? null : $view->key]),
        ];
    }

    /**
     * A table's own state as the fields of its forms, and the other tables' state.
     *
     * @param array<string, string> $own
     *
     * @return array<string, string>
     */
    private function fields(ListSlot $slot, array $own): array
    {
        $fields = [];
        foreach ($own as $name => $value) {
            $fields[$slot->field($name)] = $value;
        }

        return $fields + $slot->kept();
    }

    /**
     * The table's address after the builder's Apply: what the sheet sent, its rows read into
     * the working filter, which is left out when it is the view's own.
     *
     * @return array<array-key, mixed>
     */
    private function builtQuery(ListSlot $slot, ListDefinition $list): array
    {
        $input = $slot->input();
        $view = ListViews::chosen($this->listViews->of($list, $this->actorRole->userId()), $input->getString('view'));
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'view' => true]);

        return array_filter($query + ['view' => $view->isAll() ? null : $view->key, WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $list), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * The value of a field on a stage or a reason, as RowConditions compares it.
     */
    private function valueOf(Stage|LostReason $record, string $field): mixed
    {
        return match ($field) {
            DealStageLists::NAME => $this->name($record),
            DealStageLists::ARCHIVED => $record->isArchived(),
            default => null,
        };
    }

    private function name(Stage|LostReason $record): string
    {
        return $record instanceof Stage ? $this->screen->stage($record) : $this->screen->reason($record);
    }

    /**
     * @param list<Stage> $open the stages not archived, in their order
     *
     * @return array<string, mixed> a row of Ui:DataTable
     */
    private function stageRow(Stage $stage, array $open, WorkingState $working): array
    {
        $edit = $this->generateUrl('app_deal_stage_edit', ['id' => $stage->id()]);
        $name = $this->screen->stage($stage);
        $index = array_search($stage, $open, true);
        $move = $this->generateUrl('app_deal_stage_move', ['id' => $stage->id()]);
        $actions = [];
        if (false !== $index) {
            $actions[] = ['label' => $this->screen->trans('deals.settings.action.rename'), 'href' => $edit, 'icon' => 'pencil', 'dialog' => true];
            if ($index > 0) {
                $actions[] = ['label' => $this->screen->trans('deals.settings.action.move_up'), 'href' => $move, 'icon' => 'arrow-up', 'method' => 'POST', 'fields' => ['direction' => 'up']];
            }
            if ($index < \count($open) - 1) {
                $actions[] = ['label' => $this->screen->trans('deals.settings.action.move_down'), 'href' => $move, 'icon' => 'arrow-down', 'method' => 'POST', 'fields' => ['direction' => 'down']];
            }
            $actions[] = $this->archiveAction($this->generateUrl('app_deal_stage_archive', ['id' => $stage->id()]), $name);
        }

        return $this->row($stage, $name, $edit, $actions, $working);
    }

    /**
     * @return array<string, mixed> a row of Ui:DataTable
     */
    private function reasonRow(LostReason $reason, WorkingState $working): array
    {
        $edit = $this->generateUrl('app_deal_lost_reason_edit', ['id' => $reason->id()]);
        $name = $this->screen->reason($reason);
        $actions = $reason->isArchived() ? [] : [
            ['label' => $this->screen->trans('deals.settings.action.rename'), 'href' => $edit, 'icon' => 'pencil', 'dialog' => true],
            $this->archiveAction($this->generateUrl('app_deal_lost_reason_archive', ['id' => $reason->id()]), $name),
        ];

        return $this->row($reason, $name, $edit, $actions, $working);
    }

    /**
     * @param list<array<string, mixed>> $actions
     *
     * @return array<string, mixed>
     */
    private function row(Stage|LostReason $record, string $name, string $edit, array $actions, WorkingState $working): array
    {
        return [
            'id' => $record->id(),
            'href' => $record->isArchived() ? null : $edit,
            // A stage or reason is a small record: it opens in a modal over the list (docs/design/overlays.md).
            'opens' => Opens::DIALOG,
            'label' => $name,
            'cells' => $working->display->cells($record),
            'actions' => $actions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function archiveAction(string $href, string $name): array
    {
        return ['label' => $this->screen->trans('deals.settings.action.archive'), 'href' => $href, 'method' => 'POST',
            'confirm' => ['title' => $this->screen->trans('deals.settings.confirm.archive', ['name' => $name]), 'text' => $this->screen->trans('deals.settings.confirm.archive_text'), 'accept' => $this->screen->trans('deals.settings.action.archive'), 'cancel' => $this->screen->trans('deals.keep'), 'tone' => 'danger']];
    }

    /**
     * @param \Closure(): mixed $write
     */
    private function act(\Closure $write, ?string $flash): void
    {
        try {
            $write();
            if (null !== $flash) {
                $this->addFlash('success', $flash);
            }
        } catch (SalesRefused $refused) {
            $this->addFlash('error', $this->screen->refusal($refused));
        }
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->access->mayWork()) {
            throw new AccessDeniedHttpException('Only the owners and members of the organisation keep its sales settings.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(DealController::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
