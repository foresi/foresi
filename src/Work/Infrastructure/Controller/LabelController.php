<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Identity\Application\StaffCheck;
use App\Work\Application\LabelBadge;
use App\Work\Application\ManageLabels;
use App\Work\Application\WorkQueries;
use App\Work\Application\WorkRefused;
use App\Work\Domain\LabelColour;
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
 * which asks first and tells from how many tasks the label goes.
 */
final class LabelController extends AbstractController
{
    public const string ROUTE = 'app_labels';
    public const string TOKEN = 'work_label';

    public function __construct(
        private readonly WorkQueries $queries,
        private readonly ManageLabels $labels,
        private readonly StaffCheck $staff,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/settings/labels', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessStaff();
        $usage = $this->queries->labelUsage();

        return $this->render('work/labels/index.html.twig', [
            'columns' => [
                ['key' => 'name', 'label' => $this->translator->trans('work.labels.column.name'), 'truncate' => true],
                ['key' => 'preview', 'label' => $this->translator->trans('work.labels.column.preview')],
                ['key' => 'tasks', 'label' => $this->translator->trans('work.labels.column.tasks'), 'align' => 'right'],
            ],
            'rows' => array_map(fn (LabelBadge $label): array => $this->row($label, $usage[$label->id] ?? 0), $this->queries->labels()),
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

    #[Route('/settings/labels/{id}/delete', name: 'app_label_delete', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function delete(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        $label = $this->label($id);
        try {
            $this->labels->delete($id);
            $this->addFlash('success', $this->translator->trans('work.labels.deleted', ['name' => $label->name]));
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
        }

        return $this->redirectToRoute(self::ROUTE, status: Response::HTTP_SEE_OTHER);
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

        return $this->render('work/labels/form.html.twig', [
            'label' => $label,
            'action' => $label instanceof LabelBadge ? $this->generateUrl('app_label_edit', ['id' => $label->id]) : $this->generateUrl('app_label_new'),
            'values' => $values,
            'errors' => $errors,
            'colours' => array_map(fn (LabelColour $colour): array => [
                'value' => $colour->value,
                'label' => $this->translator->trans('work.labels.colour.'.$colour->value),
                'text' => $this->translator->trans('work.labels.colour.'.$colour->value.'_text'),
            ], LabelColour::cases()),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(LabelBadge $label, int $tasks): array
    {
        $edit = $this->generateUrl('app_label_edit', ['id' => $label->id]);

        return [
            'id' => $label->id,
            'href' => $edit,
            'label' => $label->name,
            'cells' => [
                'name' => $label->name,
                'preview' => ['badges' => [['label' => $label->name, 'tone' => $label->colour->value]]],
                'tasks' => ['text' => (string) $tasks, 'numeric' => true],
            ],
            'actions' => [
                ['label' => $this->translator->trans('work.labels.edit'), 'href' => $edit, 'icon' => 'pencil', 'dialog' => true],
                ['label' => $this->translator->trans('work.labels.delete'), 'href' => $this->generateUrl('app_label_delete', ['id' => $label->id]), 'method' => 'POST', 'confirm' => [
                    'title' => $this->translator->trans('work.labels.delete_confirm.title', ['name' => $label->name]),
                    'text' => $this->translator->trans('work.labels.delete_confirm.text', ['count' => $tasks]),
                    'accept' => $this->translator->trans('work.labels.delete'),
                    'cancel' => $this->translator->trans('work.cancel'),
                    'tone' => 'danger',
                ]],
            ],
        ];
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
