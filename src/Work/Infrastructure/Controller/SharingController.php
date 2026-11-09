<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Identity\Application\StaffCheck;
use App\Ui\Avatar;
use App\Ui\Dialog\DialogRequest;
use App\Work\Application\Choice;
use App\Work\Application\ManageSharing;
use App\Work\Application\SharedPerson;
use App\Work\Application\ShareKind;
use App\Work\Application\SharingHistoryEntry;
use App\Work\Application\SharingQuery;
use App\Work\Application\SharingView;
use App\Work\Application\WorkRefused;
use App\Work\Domain\ProjectVisibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Who sees a project, a section or a task, for the people of the
 * organisation: a dialog from the header of the project or the task, and from
 * a section in the project settings (the same URL is a page of its own). It
 * closes the item (a project to the people chosen, a section or a task as
 * private), lists the people it is shared with, each taken back by a button,
 * and shares it with one more. Each change is a plain form that comes back to
 * the dialog with its message: the open dialog shows it at once, a page goes
 * to it. An item the person does not see is not found.
 */
final class SharingController extends AbstractController
{
    public const string ROUTE = 'app_sharing';
    public const string TOKEN = 'work_sharing';
    private const string KINDS = 'projects|sections|tasks';
    /** The filters of the list and the board a project dialog goes back to. */
    private const array RETURN_QUERY = ['responsible', 'status', 'due'];

    public function __construct(
        private readonly SharingQuery $query,
        private readonly ManageSharing $sharing,
        private readonly StaffCheck $staff,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/{kind}/{id}/sharing', name: self::ROUTE, requirements: ['kind' => self::KINDS, 'id' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(string $kind, string $id, Request $request): Response
    {
        $view = $this->view($kind, $id);

        return $this->page($view, $this->returnPath($view, $request->query->getString('return')), $request);
    }

    private function page(SharingView $view, string $return, Request $request): Response
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        // A change made here shows on the page under the dialog, loaded again when it closes.
        $changed = $session instanceof FlashBagAwareSessionInterface && [] !== $session->getFlashBag()->peek('success');

        return $this->render('work/sharing/dialog.html.twig', [
            'view' => $view,
            'kind' => $view->kind->value,
            'return' => $return,
            'changed' => $changed,
            'people' => array_map(static fn (SharedPerson $person): array => [
                'id' => $person->membershipId,
                'name' => $person->name,
                'initials' => Avatar::initials($person->name),
                'tone' => Avatar::tone($person->userId),
                'role' => $person->role->value,
            ], $view->people),
            'candidates' => ['' => $this->translator->trans('work.sharing.choose')] + array_column(array_map(static fn (Choice $choice): array => [$choice->id, $choice->name], $view->candidates), 1, 0),
            'visibilities' => [
                ['value' => ProjectVisibility::Organisation->value, 'label' => $this->translator->trans('work.sharing.visibility.organisation'), 'text' => $this->translator->trans('work.sharing.visibility.organisation_text')],
                ['value' => ProjectVisibility::Chosen->value, 'label' => $this->translator->trans('work.sharing.visibility.chosen'), 'text' => $this->translator->trans('work.sharing.visibility.chosen_text')],
            ],
            'history' => array_map(fn (SharingHistoryEntry $entry): array => $this->historyItem($view->kind, $entry), $view->history),
        ]);
    }

    /**
     * Closes or opens the item: the visibility of a project, the private switch of a section or a task.
     */
    #[Route('/{kind}/{id}/sharing/private', name: 'app_sharing_private', requirements: ['kind' => self::KINDS, 'id' => Requirement::UUID_V7], methods: ['POST'])]
    public function private(string $kind, string $id, Request $request): Response
    {
        $view = $this->view($kind, $id);
        $this->checkToken($request);
        if (ShareKind::Project === $view->kind) {
            $visibility = ProjectVisibility::tryFrom($request->request->getString('visibility'));
            if (!$visibility instanceof ProjectVisibility) {
                $this->addFlash('error', $this->translator->trans('work.sharing.error.visibility'));

                return $this->back($view, $request);
            }
            $closed = ProjectVisibility::Chosen === $visibility;
        } else {
            $closed = '1' === $request->request->getString('private');
        }

        return $this->change($view, $request, function () use ($view, $closed): void {
            match ($view->kind) {
                ShareKind::Project => $this->sharing->changeVisibility($view->id, $closed ? ProjectVisibility::Chosen : ProjectVisibility::Organisation),
                ShareKind::Section => $this->sharing->makeSectionPrivate($view->id, $closed),
                ShareKind::Task => $this->sharing->makeTaskPrivate($view->id, $closed),
            };
        }, 'work.sharing.'.$view->kind->value.'.'.($closed ? 'closed' : 'opened'));
    }

    #[Route('/{kind}/{id}/sharing/people', name: 'app_sharing_add', requirements: ['kind' => self::KINDS, 'id' => Requirement::UUID_V7], methods: ['POST'])]
    public function add(string $kind, string $id, Request $request): Response
    {
        $view = $this->view($kind, $id);
        $this->checkToken($request);
        $person = $request->request->getString('person');
        $names = array_column(array_map(static fn (Choice $choice): array => [$choice->id, $choice->name], $view->candidates), 1, 0);
        if (!isset($names[$person])) {
            $this->addFlash('error', $this->translator->trans('work.refused.share_person'));

            return $this->back($view, $request);
        }

        return $this->change($view, $request, fn () => match ($view->kind) {
            ShareKind::Project => $this->sharing->shareProject($view->id, $person),
            ShareKind::Section => $this->sharing->shareSection($view->id, $person),
            ShareKind::Task => $this->sharing->shareTask($view->id, $person),
        }, 'work.sharing.shared', ['name' => $names[$person]]);
    }

    #[Route('/{kind}/{id}/sharing/people/{membershipId}/remove', name: 'app_sharing_remove', requirements: ['kind' => self::KINDS, 'id' => Requirement::UUID_V7, 'membershipId' => Requirement::UUID], methods: ['POST'])]
    public function remove(string $kind, string $id, string $membershipId, Request $request): Response
    {
        $view = $this->view($kind, $id);
        $this->checkToken($request);
        $names = array_column(array_map(static fn (SharedPerson $person): array => [$person->membershipId, $person->name], $view->people), 1, 0);
        if (!isset($names[$membershipId])) {
            // Taken back already: nothing to tell.
            return $this->back($view, $request);
        }

        return $this->change($view, $request, fn () => match ($view->kind) {
            ShareKind::Project => $this->sharing->unshareProject($view->id, $membershipId),
            ShareKind::Section => $this->sharing->unshareSection($view->id, $membershipId),
            ShareKind::Task => $this->sharing->unshareTask($view->id, $membershipId),
        }, 'work.sharing.unshared', ['name' => $names[$membershipId]]);
    }

    /**
     * Runs one change and goes back to the dialog with its message; a refusal is told there.
     *
     * @param callable(): mixed     $change
     * @param array<string, string> $parameters of the message
     */
    private function change(SharingView $view, Request $request, callable $change, string $done, array $parameters = []): Response
    {
        try {
            $change();
            $this->addFlash('success', $this->translator->trans($done, $parameters));
        } catch (WorkRefused $refused) {
            if ('unknown' === $refused->reason) {
                throw new NotFoundHttpException($refused->getMessage(), $refused);
            }
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
        }

        return $this->back($view, $request);
    }

    /**
     * The item as the dialog shows it, for whom may share it; someone who may not is denied.
     */
    private function view(string $kind, string $id): SharingView
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only the people of the organisation share its work.');
        }
        try {
            return $this->query->of(ShareKind::from($kind), $id);
        } catch (WorkRefused $refused) {
            if ('unknown' === $refused->reason) {
                throw new NotFoundHttpException($refused->getMessage(), $refused);
            }
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }
    }

    /**
     * The dialog again with the message of the change: shown at once inside the dialog, which
     * stays open; a page goes to it. Whoever no longer sees the item goes to the projects.
     */
    private function back(SharingView $before, Request $request): Response
    {
        $return = $this->returnPath($before, $request->request->getString('return'));
        try {
            $view = $this->query->of($before->kind, $before->id);
        } catch (WorkRefused) {
            return $this->redirectToRoute(ProjectController::ROUTE, status: Response::HTTP_SEE_OTHER);
        }
        if (DialogRequest::is($request)) {
            return $this->page($view, $return, $request);
        }

        return $this->redirectToRoute(self::ROUTE, ['kind' => $view->kind->value, 'id' => $view->id, 'return' => $return], Response::HTTP_SEE_OTHER);
    }

    /**
     * The page the dialog was opened from: the list or the board of a project with its
     * filters, the settings of the project of a section, the task; anything else is the first.
     */
    private function returnPath(SharingView $view, string $return): string
    {
        $places = match ($view->kind) {
            ShareKind::Project => [$this->generateUrl(ProjectController::PROJECT_ROUTE, ['id' => $view->id]), $this->generateUrl(ProjectController::BOARD_ROUTE, ['id' => $view->id])],
            ShareKind::Section => [$this->generateUrl(ProjectSettingsController::ROUTE, ['id' => $view->projectId])],
            ShareKind::Task => [$this->generateUrl(TaskController::ROUTE, ['id' => $view->id])],
        };
        $parts = parse_url($return);
        $path = \is_array($parts) && !isset($parts['scheme']) && !isset($parts['host']) ? ($parts['path'] ?? '') : '';
        if (!\in_array($path, $places, true)) {
            return $places[0];
        }
        $fields = [];
        parse_str(\is_array($parts) ? $parts['query'] ?? '' : '', $fields);
        $query = [];
        foreach (self::RETURN_QUERY as $name) {
            if (ShareKind::Project === $view->kind && isset($fields[$name]) && \is_string($fields[$name]) && '' !== $fields[$name]) {
                $query[$name] = $fields[$name];
            }
        }

        return $path.([] === $query ? '' : '?'.http_build_query($query));
    }

    /**
     * @return array{time: string, datetime: string, icon: string, text: string}
     */
    private function historyItem(ShareKind $kind, SharingHistoryEntry $entry): array
    {
        $first = null;
        $parts = [];
        if (null !== $entry->closed) {
            $parts[] = $this->translator->trans('work.history.private.'.$kind->value.'.'.($entry->closed ? 'closed' : 'opened'));
        }
        foreach (['shared' => $entry->sharedWith, 'unshared' => $entry->unsharedWith] as $key => $names) {
            if ([] !== $names) {
                $parts[] = $this->translator->trans('work.history.'.$key, ['names' => implode(', ', $names)]);
                $first ??= $key;
            }
        }

        return [
            'time' => $entry->occurredAt->format($this->translator->trans('format.datetime')),
            'datetime' => $entry->occurredAt->format(\DATE_ATOM),
            'icon' => null === $entry->closed ? ('shared' === $first ? 'user-plus' : 'users') : 'lock',
            'text' => $this->translator->trans('work.history.entry', [
                'who' => $entry->userName ?? $this->translator->trans('work.history.system'),
                'what' => implode('; ', $parts),
            ]),
        ];
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
