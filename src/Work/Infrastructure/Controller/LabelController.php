<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Controller;

use Foresi\Identity\Contract\StaffCheck;
use Foresi\Shared\Application\View\ListSlot;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Infrastructure\View\FilterSheet;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Contract\WorkingState;
use Foresi\Ui\View\ListTable;
use Foresi\Work\Application\LabelBadge;
use Foresi\Work\Application\ManageLabels;
use Foresi\Work\Application\PurgeTrashedLabels;
use Foresi\Work\Application\TrashedLabel;
use Foresi\Work\Application\View\LabelLists;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Domain\LabelColour;
use Foresi\Work\Infrastructure\Column\LabelColumns;
use Foresi\Work\Infrastructure\Column\LabelRow;
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
 * The labels of the organisation in the settings, for the staff: the list with
 * how each looks and how many tasks carry it, a new label and a change of one
 * in a dialog over it (the same URLs are pages of their own), and the delete,
 * which asks no question: the label goes to the trash and the message undoes
 * it, as for tasks (Shopify's Toast API recommends Undo over a dialog for what
 * can be undone). The list has views (docs/design/list-pages.md, rule 8): All,
 * and Trash, which lists the labels deleted with who deleted them and when;
 * restoring one puts it back on its tasks.
 */
final class LabelController extends AbstractController
{
    public const string ROUTE = 'app_labels';
    public const string TOKEN = 'work_label';
    public const string TRASH_ROUTE = 'app_label_trash';

    public function __construct(
        private readonly WorkQueries $queries,
        private readonly ManageLabels $labels,
        private readonly StaffCheck $staff,
        private readonly TranslatorInterface $translator,
        private readonly LabelLists $lists,
        private readonly ListTable $listTable,
        private readonly FilterSheet $filterSheet,
    ) {
    }

    #[Route('/settings/labels', name: self::ROUTE, methods: ['GET'])]
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
        $usage = $this->queries->labelUsage();
        $records = [
            ...array_map(static fn (LabelBadge $label): LabelRow => LabelRow::of($label, $usage[$label->id] ?? 0), $this->queries->labels()),
            ...array_map(static fn (TrashedLabel $label): LabelRow => LabelRow::trashed($label, $usage[$label->id] ?? 0), $this->queries->labelTrash()),
        ];
        $trash = static fn (ViewFilter $shown): bool => [] !== $shown->on(LabelLists::TRASHED);
        $page = $this->listTable->of($slot, $list, LabelColumns::LIST, self::ROUTE, 'labels', $records, $this->row(...), $this->valueOf(...), static fn (LabelRow $row): string => $row->name, $this->translator->trans('work.labels.search'), LabelLists::TRASHED, static fn (ViewFilter $shown): array => ['trash' => $trash($shown)]);

        return $this->render('@Work/labels/index.html.twig', [
            'labels' => $page->with($this->filterSheet->of($list, $page->shown, $page->href, pinned: $page->pinned, slot: $slot)),
            'trash' => $trash($page->shown),
            'days' => PurgeTrashedLabels::DAYS,
        ]);
    }

    #[Route('/settings/labels/new', name: 'app_label_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyUnlessStaff();

        return $this->form($request, null, ['name' => '', 'colour' => LabelColour::Gray->value]);
    }

    #[Route('/settings/labels/{id}/edit', name: 'app_label_edit', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $label = $this->label($id);

        return $this->form($request, $label, ['name' => $label->name, 'colour' => $label->colour->value]);
    }

    /**
     * Puts the label in the trash, with no question first: the message says so
     * and undoes it.
     */
    #[Route('/settings/labels/{id}/delete', name: 'app_label_delete', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function delete(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $label = $this->label($id);
        try {
            $this->labels->delete($id);
            $this->addFlash(TaskController::UNDO_FLASH, [
                'message' => $this->translator->trans('work.labels.trash.deleted', ['name' => $label->name]),
                'label' => $this->translator->trans('work.trash.undo'),
                'href' => $this->generateUrl('app_label_restore', ['id' => $id]),
                'token' => self::TOKEN,
            ]);
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
        }

        return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
    }

    /**
     * The old address of the trash of labels, which is the list's Trash view now.
     */
    #[Route('/settings/labels/trash', name: self::TRASH_ROUTE, methods: ['GET'])]
    public function trash(): RedirectResponse
    {
        $this->denyUnlessStaff();

        return $this->redirectToRoute(self::ROUTE, ['view' => LabelLists::TRASH]);
    }

    /**
     * Takes the label out of the trash and goes back to where it was asked:
     * the trash, or the list whose message undid a deletion.
     */
    #[Route('/settings/labels/{id}/restore', name: 'app_label_restore', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function restore(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        try {
            $label = $this->labels->restore($id);
            $this->addFlash('success', $this->translator->trans('work.labels.trash.restored', ['name' => $label->name()]));
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
        }

        return $this->redirectToRoute(self::ROUTE, 'trash' === $request->request->getString('from') ? ['view' => LabelLists::TRASH] : [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array{name: string, colour: string} $values
     */
    private function form(Request $request, ?LabelBadge $label, array $values): Response
    {
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = ['name' => trim($request->request->getString('name')), 'colour' => $request->request->getString('colour')];
            $colour = LabelColour::tryFrom($values['colour']);
            if ('' === $values['name']) {
                $errors['name'] = $this->translator->trans('work.labels.error.name_required');
            }
            if (!$colour instanceof LabelColour) {
                $errors['colour'] = $this->translator->trans('work.labels.error.colour');
            }
            if ([] === $errors && $colour instanceof LabelColour) {
                try {
                    if (!$label instanceof LabelBadge) {
                        $this->labels->create($values['name'], $colour);
                    } else {
                        if ($values['name'] !== $label->name) {
                            $this->labels->rename($label->id, $values['name']);
                        }
                        if ($colour !== $label->colour) {
                            $this->labels->recolour($label->id, $colour);
                        }
                    }
                    $this->addFlash('success', $this->translator->trans('work.labels.saved', ['name' => $values['name']]));

                    return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
                } catch (WorkRefused $refused) {
                    $this->notFoundIfUnknown($refused);
                    $errors[\in_array($refused->reason, ['name', 'label_taken'], true) ? 'name' : 'form'] = $this->translator->trans('work.refused.'.$refused->reason);
                }
            }
        }

        return $this->render('@Work/labels/form.html.twig', [
            'label' => $label,
            'action' => $label instanceof LabelBadge ? $this->generateUrl('app_label_edit', ['id' => $label->id]) : $this->generateUrl('app_label_new'),
            'values' => $values,
            'errors' => $errors,
            'colours' => array_map(fn (LabelColour $colour): array => [
                'value' => $colour->value,
                'label' => $this->translator->trans('work.labels.colour.'.$colour->value),
            ], LabelColour::cases()),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @return array<string, mixed> a row of Ui:DataTable
     */
    private function row(LabelRow $label, WorkingState $working): array
    {
        $row = ['id' => $label->id, 'label' => $label->name, 'cells' => $working->display->cells($label)];
        if ($label->isTrashed()) {
            return $row + ['actions' => [[
                'label' => $this->translator->trans('work.trash.restore'),
                'href' => $this->generateUrl('app_label_restore', ['id' => $label->id]),
                'method' => 'POST',
                'icon' => 'archive-restore',
                'fields' => ['from' => 'trash'],
            ]]];
        }
        $edit = $this->generateUrl('app_label_edit', ['id' => $label->id]);

        return $row + [
            'href' => $edit,
            // A label is a small record: it opens in a modal over the list (docs/design/overlays.md).
            'opens' => Opens::DIALOG,
            'actions' => [
                ['label' => $this->translator->trans('work.labels.edit'), 'href' => $edit, 'icon' => 'pencil', 'dialog' => true],
                ['label' => $this->translator->trans('work.labels.delete'), 'href' => $this->generateUrl('app_label_delete', ['id' => $label->id]), 'method' => 'POST'],
            ],
        ];
    }

    /**
     * The value of a field of the list on a label, as RowConditions compares it.
     */
    private function valueOf(LabelRow $label, string $field): mixed
    {
        return match ($field) {
            LabelLists::NAME => $label->name,
            LabelLists::TASKS => $label->tasks,
            LabelLists::TRASHED => $label->isTrashed(),
            default => null,
        };
    }

    private function label(string $id): LabelBadge
    {
        foreach ($this->queries->labels() as $label) {
            if ($label->id === $id) {
                return $label;
            }
        }

        throw new NotFoundHttpException();
    }

    private function notFoundIfUnknown(WorkRefused $refused): void
    {
        if ('unknown' === $refused->reason) {
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
    }

    private function denyUnlessStaff(): void
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only the staff manage the labels of the organisation.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
