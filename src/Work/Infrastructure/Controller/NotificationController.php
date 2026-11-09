<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Audit\Application\AuditActor;
use App\Identity\Application\OpenToGuest;
use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Work\Application\ManageNotifications;
use App\Work\Application\NotificationFilter;
use App\Work\Application\NotificationQueries;
use App\Work\Application\NotificationRow;
use App\Work\Application\WorkRefused;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
 * group theirs. "Nové" holds what is not done, "Hotové" what is (GitHub
 * Done, ClickUp Cleared), kept three months; both narrow to mentions or to
 * assignments. Each row is done or back again; the header reads them all.
 */
#[OpenToGuest]
final class NotificationController extends AbstractController
{
    public const string ROUTE = 'app_notifications';
    public const string TOKEN = 'work_notifications';
    private const string DONE = 'done';

    public function __construct(
        private readonly NotificationQueries $queries,
        private readonly ManageNotifications $notifications,
        private readonly People $people,
        private readonly AuditActor $actor,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/notifications', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $me = $this->me();
        [$done, $filter, $page] = $this->view($request);
        $inbox = $this->queries->inbox($me, $filter, $done, $page);
        if ([] === $inbox->rows && $page > 1) {
            return $this->redirect($this->inboxUrl($done, $filter));
        }
        $url = $this->generateUrl(self::ROUTE);
        $back = $this->query($done, $filter, $page);

        return $this->render('work/notifications/index.html.twig', [
            'done' => $done,
            'filter' => $filter->value,
            'unread' => $this->queries->unreadCount($me),
            'tabs' => [
                ['label' => $this->translator->trans('work.notifications.tab.new'), 'href' => $url.$this->query(false, $filter), 'active' => !$done],
                ['label' => $this->translator->trans('work.notifications.tab.done'), 'href' => $url.$this->query(true, $filter), 'active' => $done],
            ],
            'filters' => array_combine(
                array_map(static fn (NotificationFilter $case): string => $case->value, NotificationFilter::cases()),
                array_map(fn (NotificationFilter $case): string => $this->translator->trans('work.notifications.filter.'.$case->value), NotificationFilter::cases()),
            ),
            'columns' => [
                ['key' => 'task', 'label' => $this->translator->trans('work.notifications.column.task'), 'truncate' => true],
                ['key' => 'project', 'label' => $this->translator->trans('work.task.field.project'), 'truncate' => true, 'phone' => false],
                ['key' => 'event', 'label' => $this->translator->trans('work.notifications.column.event'), 'truncate' => true, 'phone' => false],
                ['key' => 'at', 'label' => $this->translator->trans('work.notifications.column.at'), 'phone' => false],
            ],
            'rows' => array_map(fn (NotificationRow $row): array => $this->row($row, $back), $inbox->rows),
            'page' => $inbox->page,
            'pages' => $inbox->page + ($inbox->hasNext ? 1 : 0),
            'baseUrl' => $url.$this->query($done, $filter),
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
     * The tab, the filter and the page asked for; anything else is the first page of everything new.
     *
     * @return array{bool, NotificationFilter, int}
     */
    private function view(Request $request): array
    {
        return [
            self::DONE === $request->query->getString('tab'),
            NotificationFilter::tryFrom($request->query->getString('filter')) ?? NotificationFilter::All,
            max(1, $request->query->getInt('page', 1)),
        ];
    }

    private function query(bool $done, NotificationFilter $filter, int $page = 1): string
    {
        $query = array_filter([
            'tab' => $done ? self::DONE : null,
            'filter' => NotificationFilter::All === $filter ? null : $filter->value,
            'page' => $page > 1 ? $page : null,
        ], static fn (string|int|null $value): bool => null !== $value);

        return [] === $query ? '' : '?'.http_build_query($query);
    }

    private function inboxUrl(bool $done, NotificationFilter $filter, int $page = 1): string
    {
        return $this->generateUrl(self::ROUTE).$this->query($done, $filter, $page);
    }

    /**
     * Back to the view the action was asked from.
     */
    private function back(Request $request): RedirectResponse
    {
        return $this->redirect($this->inboxUrl(...$this->view($request)));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(NotificationRow $row, string $back): array
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
            'label' => $row->taskTitle,
            'unread' => !$row->read,
            'secondary' => implode(' · ', [$row->projectName, $event, $at]),
            'cells' => [
                'task' => $row->taskTitle,
                'project' => $row->projectName,
                'event' => $event,
                'at' => $at,
            ],
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
        $userId = $this->actor->userId();
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
