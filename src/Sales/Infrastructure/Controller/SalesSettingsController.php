<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Controller;

use Foresi\Sales\Application\Api\ArchiveDealStageInput;
use Foresi\Sales\Application\Api\ArchiveLostReasonInput;
use Foresi\Sales\Application\Api\CreateDealStageInput;
use Foresi\Sales\Application\Api\CreateLostReasonInput;
use Foresi\Sales\Application\Api\ReorderDealStagesInput;
use Foresi\Sales\Application\Api\UpdateDealStageInput;
use Foresi\Sales\Application\Api\UpdateLostReasonInput;
use Foresi\Sales\Application\LostReasonWrites;
use Foresi\Sales\Application\SalesAccess;
use Foresi\Sales\Application\SalesRefused;
use Foresi\Sales\Application\SalesStore;
use Foresi\Sales\Application\StageWrites;
use Foresi\Sales\Domain\LostReason;
use Foresi\Sales\Domain\Stage;
use Foresi\Sales\Infrastructure\Screen\DealScreen;
use Foresi\Ui\Contract\Opens;
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
 * so, where Pipedrive would delete its deals with it.
 */
final class SalesSettingsController extends AbstractController
{
    public const string ROUTE = 'app_sales_settings';

    public function __construct(
        private readonly SalesAccess $access,
        private readonly SalesStore $store,
        private readonly StageWrites $stages,
        private readonly LostReasonWrites $reasons,
        private readonly DealScreen $screen,
    ) {
    }

    #[Route('/settings/sales', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessStaff();
        $stages = $this->store->stages(true);
        $open = array_values(array_filter($stages, static fn (Stage $stage): bool => !$stage->isArchived()));
        $columns = [
            ['key' => 'name', 'label' => $this->screen->trans('sales.settings.column.name'), 'truncate' => true],
            ['key' => 'key', 'label' => $this->screen->trans('sales.settings.column.key'), 'phone' => false],
            ['key' => 'state', 'label' => $this->screen->trans('sales.settings.column.state')],
        ];

        return $this->render('@Sales/settings/index.html.twig', [
            'columns' => $columns,
            'stages' => array_map(fn (Stage $stage): array => $this->stageRow($stage, $open), $stages),
            'reasons' => array_map($this->reasonRow(...), $this->store->lostReasons(true)),
        ]);
    }

    #[Route('/settings/sales/stages/new', name: 'app_sales_stage_new', methods: ['GET', 'POST'])]
    public function newStage(Request $request): Response
    {
        $this->denyUnlessStaff();

        return $this->form($request, 'stage', null, null, ['name' => '']);
    }

    #[Route('/settings/sales/stages/{id}/edit', name: 'app_sales_stage_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function editStage(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $stage = $this->store->stage($id) ?? throw new NotFoundHttpException();

        return $this->form($request, 'stage', $stage, $stage->name(), ['name' => $this->screen->stage($stage)]);
    }

    /**
     * Moves a stage one place up or down among the stages not archived.
     */
    #[Route('/settings/sales/stages/{id}/move', name: 'app_sales_stage_move', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
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

    #[Route('/settings/sales/stages/{id}/archive', name: 'app_sales_stage_archive', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function archiveStage(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $stage = $this->store->stage($id) ?? throw new NotFoundHttpException();
        $this->act(fn (): mixed => $this->stages->archive(new ArchiveDealStageInput($id)), $this->screen->trans('sales.settings.flash.archived', ['name' => $this->screen->stage($stage)]));

        return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/settings/sales/lost-reasons/new', name: 'app_sales_lost_reason_new', methods: ['GET', 'POST'])]
    public function newReason(Request $request): Response
    {
        $this->denyUnlessStaff();

        return $this->form($request, 'reason', null, null, ['name' => '']);
    }

    #[Route('/settings/sales/lost-reasons/{id}/edit', name: 'app_sales_lost_reason_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function editReason(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $reason = $this->store->lostReason($id) ?? throw new NotFoundHttpException();

        return $this->form($request, 'reason', $reason, $reason->name(), ['name' => $this->screen->reason($reason)]);
    }

    #[Route('/settings/sales/lost-reasons/{id}/archive', name: 'app_sales_lost_reason_archive', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function archiveReason(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $reason = $this->store->lostReason($id) ?? throw new NotFoundHttpException();
        $this->act(fn (): mixed => $this->reasons->archive(new ArchiveLostReasonInput($id)), $this->screen->trans('sales.settings.flash.archived', ['name' => $this->screen->reason($reason)]));

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
                $this->addFlash('success', $this->screen->trans('sales.settings.flash.saved', ['name' => $values['name']]));

                return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
            } catch (SalesRefused $refused) {
                $errors = ['name' => $this->screen->refusal($refused)];
            }
        }
        $routes = 'stage' === $kind ? ['app_sales_stage_edit', 'app_sales_stage_new'] : ['app_sales_lost_reason_edit', 'app_sales_lost_reason_new'];

        return $this->render('@Sales/settings/record.html.twig', [
            'kind' => $kind,
            'record' => $record,
            'values' => $values,
            'errors' => $errors,
            'action' => null === $record ? $this->generateUrl($routes[1]) : $this->generateUrl($routes[0], ['id' => $record->id()]),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
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
    private function stageRow(Stage $stage, array $open): array
    {
        $edit = $this->generateUrl('app_sales_stage_edit', ['id' => $stage->id()]);
        $name = $this->screen->stage($stage);
        $index = array_search($stage, $open, true);
        $move = $this->generateUrl('app_sales_stage_move', ['id' => $stage->id()]);
        $actions = [];
        if (false !== $index) {
            $actions[] = ['label' => $this->screen->trans('sales.settings.action.rename'), 'href' => $edit, 'icon' => 'pencil', 'dialog' => true];
            if ($index > 0) {
                $actions[] = ['label' => $this->screen->trans('sales.settings.action.move_up'), 'href' => $move, 'icon' => 'arrow-up', 'method' => 'POST', 'fields' => ['direction' => 'up']];
            }
            if ($index < \count($open) - 1) {
                $actions[] = ['label' => $this->screen->trans('sales.settings.action.move_down'), 'href' => $move, 'icon' => 'arrow-down', 'method' => 'POST', 'fields' => ['direction' => 'down']];
            }
            $actions[] = $this->archiveAction($this->generateUrl('app_sales_stage_archive', ['id' => $stage->id()]), $name);
        }

        return $this->row($stage, $name, $edit, $actions);
    }

    /**
     * @return array<string, mixed> a row of Ui:DataTable
     */
    private function reasonRow(LostReason $reason): array
    {
        $edit = $this->generateUrl('app_sales_lost_reason_edit', ['id' => $reason->id()]);
        $name = $this->screen->reason($reason);
        $actions = $reason->isArchived() ? [] : [
            ['label' => $this->screen->trans('sales.settings.action.rename'), 'href' => $edit, 'icon' => 'pencil', 'dialog' => true],
            $this->archiveAction($this->generateUrl('app_sales_lost_reason_archive', ['id' => $reason->id()]), $name),
        ];

        return $this->row($reason, $name, $edit, $actions);
    }

    /**
     * @param list<array<string, mixed>> $actions
     *
     * @return array<string, mixed>
     */
    private function row(Stage|LostReason $record, string $name, string $edit, array $actions): array
    {
        $badges = $record->isArchived() ? [['label' => $this->screen->trans('sales.settings.archived'), 'tone' => 'gray']] : [];

        return [
            'id' => $record->id(),
            'href' => $record->isArchived() ? null : $edit,
            // A stage or reason is a small record: it opens in a modal over the list (docs/design/overlays.md).
            'opens' => Opens::DIALOG,
            'label' => $name,
            'cells' => ['name' => $name, 'key' => $record->key() ?? '', 'state' => [] === $badges ? '' : ['badges' => $badges]],
            'actions' => $actions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function archiveAction(string $href, string $name): array
    {
        return ['label' => $this->screen->trans('sales.settings.action.archive'), 'href' => $href, 'method' => 'POST',
            'confirm' => ['title' => $this->screen->trans('sales.settings.confirm.archive', ['name' => $name]), 'text' => $this->screen->trans('sales.settings.confirm.archive_text'), 'accept' => $this->screen->trans('sales.settings.action.archive'), 'cancel' => $this->screen->trans('sales.keep'), 'tone' => 'danger']];
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
