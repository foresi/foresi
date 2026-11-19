<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Controller;

use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Application\OpenToGuest;
use Foresi\Identity\Application\People;
use Foresi\Identity\Domain\Membership;
use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\FilterRows;
use Foresi\Shared\Application\View\ListView;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Column\RememberedDisplay;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Contract\WorkingDisplay;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ViewBar;
use Foresi\Work\Application\ManageNotifications;
use Foresi\Work\Application\NotificationQueries;
use Foresi\Work\Application\NotificationRow;
use Foresi\Work\Application\View\NotificationLists;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Infrastructure\Column\NotificationCells;
use Foresi\Work\Infrastructure\Column\NotificationColumns;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The inbox of the signed-in person, a guest's too: one row per task, newest
 * first, with its latest event and who did it, as ClickUp, GitHub and Linear
 * group theirs. It runs on saved views as the other lists do (NotificationLists):
 * "Nové" holds what is not done, "Hotové" what is (GitHub Done, ClickUp Cleared),
 * kept three months; "+ Filter" narrows to mentions, assignments, unread rows or
 * a time. Each row is done or back again; the header reads them all.
 *
 * The old addresses keep working: ?tab=done opens the Done view, ?filter=mentions
 * and ?filter=assigned become a condition of the working filter.
 */
#[OpenToGuest]
final class NotificationController extends AbstractController
{
    public const string ROUTE = 'app_notifications';
    public const string TOKEN = 'work_notifications';
    /** The one order of the inbox, newest first. */
    private const string ORDER = '-at';
    /** The parts of the inbox's address an action carries back to it. */
    private const array LIST_QUERY = ['view', 'tab', 'filter', WorkingFilter::PARAM, 'page', WorkingDisplay::COLUMNS, WorkingDisplay::DENSITY];

    public function __construct(private readonly NotificationLists $notificationLists,
        private readonly NotificationQueries $queries,
        private readonly ManageNotifications $notifications,
        private readonly People $people,
        private readonly AuditActor $auditActor,
        private readonly TranslatorInterface $translator,
        private readonly ListViews $listViews,
        private readonly ActorRole $actor,
        private readonly RememberedDisplay $rememberedDisplay,
        private readonly WorkingDisplay $workingDisplay,
        private readonly ViewBar $viewBar,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/notifications', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $me = $this->me();
        // The builder's rows (spec D11) become the list's working filter, in its address.
        if ($request->query->has(FilterRows::MARKER)) {
            return $this->redirectToRoute(self::ROUTE, $this->builtQuery($request->query), Response::HTTP_SEE_OTHER);
        }
        [$views, $view, $shown] = $this->views($request->query);
        $list = $this->notificationLists->list();
        $filterParam = WorkingFilter::param($shown, $view->filter);
        $page = max(1, $request->query->getInt('page', 1));
        $inbox = $this->queries->inbox($me, $shown, $page);
        $query = array_filter(['view' => $view->isAll() ? null : $view->key, WorkingFilter::PARAM => $filterParam], static fn (?string $value): bool => null !== $value);
        // Columns and density are kept for staff with the view; a guest has no saved views, only the address.
        $working = $this->actor->isStaff()
            ? $this->rememberedDisplay->read(NotificationColumns::LIST, [], $list->key, $view, $request->query->all())
            : $this->workingDisplay->read(NotificationColumns::LIST, [], $view->display, $request->query->all());
        $query += $working->query;
        if ([] === $inbox->rows && $page > 1) {
            return $this->redirectToRoute(self::ROUTE, $query);
        }
        $back = $this->generateUrl(self::ROUTE, $page > 1 ? $query + ['page' => (string) $page] : $query);
        $back = substr($back, \strlen($this->generateUrl(self::ROUTE)));
        $bar = $this->viewBar->of($list, $views, $view, $shown, self::ORDER, self::ORDER, $working, self::ROUTE, []);
        $done = \in_array(true, array_map(static fn (Condition $condition): bool => true === $condition->value, $shown->on(NotificationLists::DONE)), true);

        return $this->render('@Work/notifications/index.html.twig', [
            'done' => $done,
            'staff' => $this->actor->isStaff(),
            'unread' => $this->queries->unreadCount($me),
            'view' => $view->isAll() ? '' : $view->key,
            'working' => $filterParam ?? '',
            'workingQuery' => $working->query,
            'filtered' => null !== $filterParam,
            'emptyState' => $this->emptyState($view),
            ...$this->filterSheet->of($list, $shown, fn (ViewFilter $next): string => $this->generateUrl(self::ROUTE, [WorkingFilter::PARAM => WorkingFilter::param($next, $view->filter)] + array_diff_key($query, [WorkingFilter::PARAM => true]))),
            ...$bar,
            ...$this->tabs($views, $view->key, $query, $bar['viewBar']['modified']),
            'columns' => $working->display->table(),
            'rows' => array_map(fn (NotificationRow $row): array => $this->row($row, $working, $back), $inbox->rows),
            'density' => $working->tableDensity(),
            'picker' => $this->picker($working, $query),
            'page' => $inbox->page,
            'pages' => $inbox->page + ($inbox->hasNext ? 1 : 0),
            'baseUrl' => $this->generateUrl(self::ROUTE, $query),
            'readAllUrl' => $this->generateUrl('app_notifications_read_all').$back,
        ]);
    }

    #[Route('/notifications/read', name: 'app_notifications_read_all', methods: ['POST'])]
    public function readAll(Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->notifications->markAllRead();

        return $this->back($request);
    }

    #[Route('/notifications/{id}/done', name: 'app_notification_done', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function done(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->refusedAsNotFound(fn () => $this->notifications->markDone($id));

        return $this->back($request);
    }

    #[Route('/notifications/{id}/undone', name: 'app_notification_undone', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function undone(string $id, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        $this->refusedAsNotFound(fn () => $this->notifications->markUndone($id));

        return $this->back($request);
    }

    /**
     * The inbox's views as its address names them (spec D10): the person's views, the
     * one ?view= names (or ?tab=, the address of the tabs the inbox had), its All when
     * none, and the filter the list shows, the view's own unless f= changed it. The
     * ?filter= of the select the inbox had narrows it as a condition (D11).
     *
     * @param InputBag<string> $input
     *
     * @return array{list<ListView>, ListView, ViewFilter}
     */
    private function views(InputBag $input): array
    {
        $list = $this->notificationLists->list();
        $views = $this->listViews->of($list, $this->actor->userId());
        $view = ListViews::chosen($views, $input->getString('view', $input->getString('tab')));
        $shown = WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $list) ?? $view->filter;
        $reason = $input->getString('filter');
        if (\in_array($reason, [NotificationLists::MENTIONS, NotificationLists::ASSIGNED], true) && [] === $shown->on(NotificationLists::REASON)) {
            $shown = $shown->with(new Condition(NotificationLists::REASON, 'is', $reason));
        }

        return [$views, $view, $shown];
    }

    /**
     * The inbox's address after the builder's Apply: what the sheet sent, its rows read
     * into the working filter, which is left out when it is the view's own.
     *
     * @param InputBag<string> $input
     *
     * @return array<string, mixed>
     */
    private function builtQuery(InputBag $input): array
    {
        [, $view] = $this->views($input);
        $query = array_diff_key($input->all(), [FilterRows::PARAM => true, FilterRows::MARKER => true, WorkingFilter::PARAM => true, 'page' => true, 'filter' => true]);

        return array_filter($query + [WorkingFilter::PARAM => WorkingFilter::param(FilterRows::parse($input->all(FilterRows::PARAM), $this->notificationLists->list()), $view->filter)], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * The tabs of the inbox and its "More views" menu (spec D7), without counts (D9).
     *
     * @param list<ListView>        $views
     * @param array<string, string> $query
     *
     * @return array{tabs: list<array<string, mixed>>, moreViews: list<array<string, mixed>>}
     */
    private function tabs(array $views, string $active, array $query, bool $modified): array
    {
        [$primary, $overflow] = ListViews::placed($views);
        $link = fn (ListView $view): array => [
            'label' => $view->translated ? $this->translator->trans($view->name) : $view->name,
            // A view opens with its own filter and columns: the changed ones belong to the view they were changed on (spec D10).
            'href' => $this->generateUrl(self::ROUTE, ['view' => $view->isAll() ? null : $view->key] + array_diff_key($query, ['view' => true, WorkingFilter::PARAM => true] + array_flip(WorkingDisplay::KEYS))),
            'active' => $view->key === $active,
            'modified' => $modified && $view->key === $active,
            'private' => $view->private,
        ];

        return ['tabs' => array_map($link, $primary), 'moreViews' => array_map($link, $overflow)];
    }

    /**
     * The Columns sheet: what it keeps of the list, and the list without the changed columns.
     *
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
            'density' => $working->density,
            // An empty density names the view's own display, which forgets the one remembered (D-display-3).
            'resetHref' => $this->generateUrl(self::ROUTE, $kept + [WorkingDisplay::DENSITY => '']),
            'modified' => $working->modified,
        ];
    }

    /**
     * What an empty inbox says: New and Done their own words, any other view that nothing matches.
     *
     * @return array{title: string, text: string}
     */
    private function emptyState(ListView $view): array
    {
        $key = match (true) {
            $view->isAll() => 'new',
            NotificationLists::DONE_VIEW === $view->key => 'done',
            default => null,
        };
        if (null === $key) {
            return ['title' => $this->translator->trans('work.notifications.empty.view'), 'text' => ''];
        }

        return ['title' => $this->translator->trans('work.notifications.empty.'.$key.'_title'), 'text' => $this->translator->trans('work.notifications.empty.'.$key.'_text')];
    }

    /**
     * Back to the view the action was asked from: only the known parts of its address.
     */
    private function back(Request $request): RedirectResponse
    {
        return $this->redirectToRoute(self::ROUTE, array_filter(array_intersect_key($request->query->all(), array_flip(self::LIST_QUERY)), \is_string(...)));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(NotificationRow $row, WorkingState $working, string $back): array
    {
        $event = $this->translator->trans('work.history.entry', [
            'who' => $row->actorName,
            'what' => $this->translator->trans('work.notifications.event.'.$row->kind->value),
        ]);
        if ($row->events > 1) {
            $event = $this->translator->trans('work.notifications.more', ['event' => $event, 'count' => $row->events - 1]);
        }
        $at = $row->at->format($this->translator->trans('format.datetime'));
        $action = $row->done
            ? ['label' => $this->translator->trans('work.notifications.undone'), 'href' => $this->generateUrl('app_notification_undone', ['id' => $row->id]).$back, 'method' => 'POST', 'icon' => 'inbox']
            : ['label' => $this->translator->trans('work.notifications.done'), 'href' => $this->generateUrl('app_notification_done', ['id' => $row->id]).$back, 'method' => 'POST', 'icon' => 'check'];

        return [
            'id' => $row->id,
            'href' => $this->generateUrl(TaskController::ROUTE, ['id' => $row->taskId]),
            'opens' => Opens::SHEET,
            'label' => $row->taskTitle,
            'unread' => !$row->read,
            'secondary' => implode(' · ', [$row->projectName, $event, $at]),
            'cells' => $working->display->cells(new NotificationCells($row->taskTitle, $row->projectName, $event, $at)),
            'actions' => [$action],
        ];
    }

    /**
     * Someone else's row is not found, as a task one does not see.
     */
    private function refusedAsNotFound(callable $action): void
    {
        try {
            $action();
        } catch (WorkRefused $refused) {
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
    }

    private function me(): Membership
    {
        $userId = $this->auditActor->userId();
        $me = null === $userId ? null : $this->people->membershipOf($userId);

        return $me ?? throw new AccessDeniedHttpException('Only a member of the organisation has an inbox.');
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
