<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Controller;

use Foresi\Activities\Application\ActivitiesAccess;
use Foresi\Activities\Application\ActivitiesRefused;
use Foresi\Activities\Application\ActivitiesStore;
use Foresi\Activities\Application\ActivityTypeWrites;
use Foresi\Activities\Application\Api\ArchiveActivityTypeInput;
use Foresi\Activities\Application\Api\CreateActivityTypeInput;
use Foresi\Activities\Application\Api\UpdateActivityTypeInput;
use Foresi\Activities\Application\View\ActivityTypeLists;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Activities\Infrastructure\Column\ActivityTypeColumns;
use Foresi\Activities\Infrastructure\Screen\ActivityScreen;
use Foresi\Shared\Application\View\ListSlot;
use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ListTable;
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
 * Settings → Activities: the activity types, each with its timing, as
 * Pipedrive and Odoo let a team keep its own activity types. A type is a small
 * record: a new one and a change open in a dialog over the list, and archiving
 * asks first. The types are a list with views (docs/design/list-pages.md, rule 8).
 * For the owners and members only.
 */
final class ActivitySettingsController extends AbstractController
{
    public const string ROUTE = 'app_activity_settings';

    public function __construct(
        private readonly ActivitiesAccess $access,
        private readonly ActivitiesStore $store,
        private readonly ActivityTypeWrites $types,
        private readonly ActivityScreen $screen,
        private readonly TranslatorInterface $translator,
        private readonly ActivityTypeLists $lists,
        private readonly ListTable $listTable,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/settings/activities', name: self::ROUTE, methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessStaff();
        $slot = ListSlot::first($request->query->all());
        $list = $this->lists->list();
        // The builder's rows (spec D11) become the list's working filter, in its address.
        $built = $this->listTable->built($slot, $list);
        if (null !== $built) {
            return $this->redirectToRoute(self::ROUTE, $built, Response::HTTP_SEE_OTHER);
        }
        $page = $this->listTable->of($slot, $list, ActivityTypeColumns::LIST, self::ROUTE, 'activity-types', $this->store->types(true), $this->row(...), $this->valueOf(...), $this->screen->typeName(...), $this->trans('activities.settings.search'));

        return $this->render('@Activities/settings/index.html.twig', [
            'types' => $page->with($this->filterSheet->of($list, $page->shown, $page->href, pinned: $page->pinned, slot: $slot)),
        ]);
    }

    #[Route('/settings/activities/types/new', name: 'app_activity_type_new', methods: ['GET', 'POST'])]
    public function newType(Request $request): Response
    {
        $this->denyUnlessStaff();

        return $this->form($request, null, ['name' => '', 'key' => '', 'timing' => ActivityTiming::Timed->value, 'defaultDurationMinutes' => '30']);
    }

    #[Route('/settings/activities/types/{id}/edit', name: 'app_activity_type_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function editType(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $type = $this->store->type($id) ?? throw new NotFoundHttpException();

        return $this->form($request, $type, ['name' => $this->screen->typeName($type), 'key' => $type->key(), 'timing' => $type->timing()->value, 'defaultDurationMinutes' => (string) $type->defaultDurationMinutes()]);
    }

    #[Route('/settings/activities/types/{id}/archive', name: 'app_activity_type_archive', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function archiveType(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $type = $this->store->type($id) ?? throw new NotFoundHttpException();
        $this->types->archive(new ArchiveActivityTypeInput($id));
        $this->addFlash('success', $this->trans('activities.settings.flash.archived', ['name' => $this->screen->typeName($type)]));

        return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $values
     */
    private function form(Request $request, ?ActivityType $type, array $values): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            foreach (array_keys($values) as $field) {
                $values[$field] = trim($request->request->getString($field));
            }
            $duration = 1 === preg_match('/^\d{1,5}$/', $values['defaultDurationMinutes']) ? (int) $values['defaultDurationMinutes'] : null;
            try {
                if ($type instanceof ActivityType) {
                    // A seeded type keeps its translated name until someone gives it another.
                    $name = null === $type->name() && $values['name'] === $this->screen->typeName($type) ? new Omitted() : $values['name'];
                    $this->types->update(new UpdateActivityTypeInput($type->id(), $name, $values['timing'], $duration));
                } else {
                    $this->types->create(new CreateActivityTypeInput($values['name'], $values['key'], $values['timing'], $duration));
                }
                $this->addFlash('success', $this->trans('activities.settings.flash.saved', ['name' => $values['name']]));

                return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
            } catch (ActivitiesRefused $refused) {
                $field = $refused->field();
                $errors = [\in_array($field, ['name', 'key', 'timing', 'defaultDurationMinutes'], true) ? $field : 'name' => $this->screen->refusal($refused)];
            }
        }

        return $this->render('@Activities/settings/type.html.twig', [
            'type' => $type,
            'values' => $values,
            'errors' => $errors,
            'timings' => array_combine(
                array_map(static fn (ActivityTiming $timing): string => $timing->value, ActivityTiming::cases()),
                array_map(fn (ActivityTiming $timing): string => $this->trans('activities.timing.'.$timing->value), ActivityTiming::cases()),
            ),
            'action' => $type instanceof ActivityType ? $this->generateUrl('app_activity_type_edit', ['id' => $type->id()]) : $this->generateUrl('app_activity_type_new'),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @return array<string, mixed> a row of Ui:DataTable
     */
    private function row(ActivityType $type, WorkingState $working): array
    {
        $edit = $this->generateUrl('app_activity_type_edit', ['id' => $type->id()]);
        $name = $this->screen->typeName($type);

        return [
            'id' => $type->id(),
            'href' => $edit,
            // A type is a small record: it opens in a modal over the list (docs/design/overlays.md).
            'opens' => Opens::DIALOG,
            'label' => $name,
            'cells' => $working->display->cells($type),
            'actions' => $type->isArchived() ? [] : [
                ['label' => $this->trans('activities.settings.action.edit'), 'href' => $edit, 'icon' => 'pencil', 'dialog' => true],
                ['label' => $this->trans('activities.settings.action.archive'), 'href' => $this->generateUrl('app_activity_type_archive', ['id' => $type->id()]), 'method' => 'POST',
                    'confirm' => ['title' => $this->trans('activities.settings.confirm.archive', ['name' => $name]), 'text' => $this->trans('activities.settings.confirm.archive_text'), 'accept' => $this->trans('activities.settings.action.archive'), 'cancel' => $this->trans('activities.keep'), 'tone' => 'danger']],
            ],
        ];
    }

    /**
     * The value of a field of the list on a type, as RowConditions compares it.
     */
    private function valueOf(ActivityType $type, string $field): mixed
    {
        return match ($field) {
            ActivityTypeLists::NAME => $this->screen->typeName($type),
            ActivityTypeLists::TIMING => $type->timing()->value,
            ActivityTypeLists::ARCHIVED => $type->isArchived(),
            default => null,
        };
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }

    private function denyUnlessStaff(): void
    {
        try {
            $this->access->denyUnlessStaff();
        } catch (ActivitiesRefused) {
            throw new AccessDeniedHttpException();
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(ActivityController::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
