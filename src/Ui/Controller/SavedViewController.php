<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Controller;

use Foresi\Shared\Application\ActorRole;
use Foresi\Shared\Application\Api\Omitted;
use Foresi\Shared\Application\RefusalKind;
use Foresi\Shared\Application\View\CreateViewInput;
use Foresi\Shared\Application\View\DeleteViewInput;
use Foresi\Shared\Application\View\ListViews;
use Foresi\Shared\Application\View\RestoreDefaultViewsInput;
use Foresi\Shared\Application\View\SavedViewRefused;
use Foresi\Shared\Application\View\SavedViews;
use Foresi\Shared\Application\View\SavedViewUserStates;
use Foresi\Shared\Application\View\UpdateViewInput;
use Foresi\Shared\Application\View\WorkingFilter;
use Foresi\Shared\Domain\View\Placement;
use Foresi\Shared\Domain\View\SavedView;
use Foresi\Shared\Domain\View\Visibility;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The saved views' forms on a list (spec D6, D7, D10), all through SavedViews: Save the
 * working state into the view, Save as new view, and Manage views (rename, private or
 * shared, a tab or in More views, delete) and Restore default views. Each returns to the
 * list it came from, with a message.
 */
final class SavedViewController extends AbstractController
{
    public const string TOKEN = 'saved_view';

    public function __construct(
        private readonly SavedViews $views,
        private readonly SavedViewUserStates $states,
        private readonly ListViews $listViews,
        private readonly ActorRole $actor,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/views/save', name: 'app_view_save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        $input = $request->request;

        return $this->attempt($request, function () use ($input): string {
            $view = $this->views->get($input->getString('ref'));
            [$columns, $density] = $this->display($input);
            // The display goes with it only when it was changed, so a view keeps its own otherwise.
            $changed = $input->getBoolean('displayChanged');
            $this->views->update(new UpdateViewInput(
                $view->id(),
                conditions: $this->conditions($input, $view->listKey()),
                sort: $this->sort($input),
                columns: $changed ? $columns : new Omitted(),
                density: $changed ? $density : new Omitted(),
            ));
            $this->forget($view);

            return $this->return($input->getString('return'));
        }, 'saved_view.saved');
    }

    #[Route('/views/new', name: 'app_view_new', methods: ['POST'])]
    public function create(Request $request): RedirectResponse
    {
        $input = $request->request;

        return $this->attempt($request, function () use ($input): string {
            $list = $input->getString('list');
            [$columns, $density] = $this->display($input);
            $view = $this->views->create(new CreateViewInput(
                $list,
                $input->getString('name'),
                Visibility::Shared->value === $input->getString('visibility') ? Visibility::Shared->value : Visibility::Private->value,
                $this->conditions($input, $list),
                $this->sort($input),
                $columns,
                $density,
                $input->getBoolean('showCount'),
                $this->placement($list, Placement::Primary->value),
            ));
            // The changes now live in the new view; the one they were made on is as saved.
            $from = '' === $input->getString('ref') ? null : $this->views->get($input->getString('ref'));
            if ($from instanceof SavedView) {
                $this->forget($from);
            }

            return $this->withView($this->return($input->getString('return')), $view->id());
        }, 'saved_view.created');
    }

    #[Route('/views/edit', name: 'app_view_edit', methods: ['POST'])]
    public function edit(Request $request): RedirectResponse
    {
        $input = $request->request;

        return $this->attempt($request, function () use ($input): string {
            $view = $this->views->get($input->getString('ref'));
            $name = $input->getString('name');
            $placement = $input->getString('placement');
            $this->views->update(new UpdateViewInput(
                $view->id(),
                name: '' === $name || $name === $view->name() ? new Omitted() : $name,
                // A view a module ships stays shared (spec D6).
                visibility: !$input->has('visibility') || null !== $view->systemKey() ? new Omitted() : $input->getString('visibility'),
                placement: '' === $placement || $placement === $view->placement()->value ? new Omitted() : $this->placement($view->listKey(), $placement, true),
            ));

            return $this->return($input->getString('return'));
        }, 'saved_view.updated');
    }

    #[Route('/views/delete', name: 'app_view_delete', methods: ['POST'])]
    public function delete(Request $request): RedirectResponse
    {
        $input = $request->request;

        return $this->attempt($request, function () use ($input): string {
            $this->views->delete(new DeleteViewInput($this->views->get($input->getString('ref'))->id()));

            return $this->return($input->getString('return'));
        }, 'saved_view.deleted');
    }

    #[Route('/views/restore', name: 'app_view_restore', methods: ['POST'])]
    public function restore(Request $request): RedirectResponse
    {
        $input = $request->request;

        return $this->attempt($request, function () use ($input): string {
            $this->views->restoreDefaults(new RestoreDefaultViewsInput($input->getString('list')));

            return $this->return($input->getString('return'));
        }, 'saved_view.restored');
    }

    /**
     * @param \Closure(): string $change the change, which answers where the list is
     */
    private function attempt(Request $request, \Closure $change, string $done): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        try {
            $to = $change();
            $this->addFlash('success', $this->translator->trans($done));
        } catch (SavedViewRefused $refused) {
            $this->addFlash('error', $this->translator->trans(match (true) {
                RefusalKind::Forbidden === $refused->kind() => 'saved_view.error.forbidden',
                'name' === $refused->field() => 'saved_view.error.name',
                'placement' === $refused->field() => 'saved_view.error.primary_full',
                default => 'saved_view.error.invalid',
            }));
            $to = $this->return($request->request->getString('return'));
        }

        return new RedirectResponse($to, Response::HTTP_SEE_OTHER);
    }

    /**
     * The list's working filter, in the form the address keeps it.
     *
     * @param InputBag<string> $input
     *
     * @return list<mixed>
     */
    private function conditions(InputBag $input, string $list): array
    {
        $filter = WorkingFilter::decode($input->getString(WorkingFilter::PARAM), $this->views->list($list));
        if (!$filter instanceof \Foresi\Shared\Application\View\ViewFilter) {
            throw SavedViewRefused::invalid('filter_invalid', 'conditions', 'The list sent no filter it accepts.');
        }

        return $filter->toArray()['conditions'];
    }

    /**
     * @param InputBag<string> $input
     *
     * @return list<mixed>
     */
    private function sort(InputBag $input): array
    {
        $sort = json_decode($input->getString('sort', '[]'), true);

        return \is_array($sort) ? array_values($sort) : [];
    }

    /**
     * The display payload the list shows, as columns and density.
     *
     * @param InputBag<string> $input
     *
     * @return array{list<mixed>|null, string|null}
     */
    private function display(InputBag $input): array
    {
        $display = json_decode($input->getString('display'), true);
        if (!\is_array($display)) {
            return [null, null];
        }
        $columns = $display['columns'] ?? null;
        $density = $display['density'] ?? null;

        return [\is_array($columns) ? array_values($columns) : null, \is_string($density) ? $density : null];
    }

    /**
     * Primary while the list has room for another tab (spec D7), else in More views; a
     * view moved to a tab when there is none left is refused.
     */
    private function placement(string $list, string $wanted, bool $strict = false): string
    {
        if (Placement::Primary->value !== $wanted) {
            return $wanted;
        }
        $views = $this->listViews->of($this->views->list($list), $this->actor->userId());
        if (\count(ListViews::placed($views)[0]) < ListViews::MAX_PRIMARY) {
            return $wanted;
        }
        if ($strict) {
            throw SavedViewRefused::invalid('primary_full', 'placement', 'A list shows at most six tabs; move another view to More views first.');
        }

        return Placement::Overflow->value;
    }

    private function forget(SavedView $view): void
    {
        $user = $this->actor->userId();
        if (null !== $user) {
            $this->states->forget($view->id(), $user);
        }
    }

    /**
     * A list's own address, never another site's.
     */
    private function return(string $to): string
    {
        return str_starts_with($to, '/') && !str_starts_with($to, '//') && !str_contains($to, '\\') ? $to : $this->generateUrl('app_dashboard');
    }

    private function withView(string $to, string $id): string
    {
        $parts = parse_url($to);
        parse_str($parts['query'] ?? '', $query);

        return ($parts['path'] ?? '/').'?'.http_build_query(['view' => $id] + array_diff_key($query, ['view' => true]));
    }
}
