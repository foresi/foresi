<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Identity\Application\StaffCheck;
use App\Work\Application\Choice;
use App\Work\Application\ManageProjects;
use App\Work\Application\ManageSections;
use App\Work\Application\ManageTime;
use App\Work\Application\ManageWorkflow;
use App\Work\Application\ProjectSettings;
use App\Work\Application\StatusChoice;
use App\Work\Application\WorkQueries;
use App\Work\Application\WorkRefused;
use App\Work\Domain\StatusType;
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
 * The settings of a project, for the staff: what it is and for whom, whether
 * its time is billable, its archiving, its sections and its statuses. Each change is a plain form that
 * comes back to the page, where a refusal is told; the removal of a status
 * asks in a dialog where its tasks go.
 */
final class ProjectSettingsController extends AbstractController
{
    public const string ROUTE = 'app_project_settings';
    public const string TOKEN = 'work_project_settings';
    private const string UUID = '/^'.Requirement::UUID.'$/';

    public function __construct(
        private readonly WorkQueries $queries,
        private readonly ManageProjects $projects,
        private readonly ManageSections $sections,
        private readonly ManageWorkflow $workflow,
        private readonly ManageTime $time,
        private readonly StaffCheck $staff,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The page, and the save of its general part: the name, the client, the
     * description and whether new time on it starts as billable.
     */
    #[Route('/projects/{id}/settings', name: self::ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function settings(string $id, Request $request): Response
    {
        $this->denyUnlessStaff();
        $settings = $this->load($id);
        $project = $settings->project;
        $values = ['name' => $project->name, 'clientId' => $project->clientId ?? '', 'clientQuery' => $project->clientName ?? '', 'description' => $project->description];
        $billable = $project->billable;
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            foreach (array_keys($values) as $field) {
                $values[$field] = 'description' === $field ? $request->request->getString($field) : trim($request->request->getString($field));
            }
            $billable = '1' === $request->request->getString('billable');
            if ('' === $values['name']) {
                $errors['name'] = $this->translator->trans('work.project.error.name_required');
            } else {
                try {
                    $this->projects->edit($id, $values['name'], '' === $values['clientId'] ? null : $values['clientId'], $values['description']);
                    if ($billable !== $project->billable) {
                        $this->time->setProjectBillableDefault($id, $billable);
                    }
                    $this->addFlash('success', $this->translator->trans('work.settings.saved'));

                    return $this->back($id);
                } catch (WorkRefused $refused) {
                    $this->notFoundIfUnknown($refused);
                    $errors[match ($refused->reason) {
                        'name' => 'name',
                        'client' => 'client',
                        default => 'form',
                    }] = $this->translator->trans('work.refused.'.$refused->reason);
                }
            }
        }

        return $this->render('work/projects/settings.html.twig', [
            'project' => $project,
            'values' => $values,
            'billable' => $billable,
            'errors' => $errors,
            'sections' => $settings->sections,
            'privateSections' => $settings->privateSections,
            'statuses' => $settings->statuses,
            'types' => $this->types(),
            'trashCount' => $this->queries->trashCount($id),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/projects/{id}/archive', name: 'app_project_archive', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function archive(string $id, Request $request): RedirectResponse
    {
        return $this->change($id, $request, fn () => $this->projects->archive($id), 'work.settings.archived');
    }

    #[Route('/projects/{id}/restore', name: 'app_project_restore', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function restore(string $id, Request $request): RedirectResponse
    {
        return $this->change($id, $request, fn () => $this->projects->restore($id), 'work.settings.restored');
    }

    /**
     * A new section at the end; an empty name adds nothing.
     */
    #[Route('/projects/{id}/sections', name: 'app_project_section_add', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function addSection(string $id, Request $request): RedirectResponse
    {
        $name = trim($request->request->getString('name'));

        return $this->change($id, $request, function () use ($id, $name): void {
            if ('' !== $name) {
                $this->sections->add($id, $name);
            }
        });
    }

    #[Route('/projects/{id}/sections/{sectionId}', name: 'app_project_section_rename', requirements: ['id' => Requirement::UUID_V7, 'sectionId' => Requirement::UUID_V7], methods: ['POST'])]
    public function renameSection(string $id, string $sectionId, Request $request): RedirectResponse
    {
        $name = trim($request->request->getString('name'));

        return $this->change($id, $request, function () use ($id, $sectionId, $name): void {
            $this->sectionAt($id, $sectionId);
            $this->sections->rename($sectionId, $name);
        }, 'work.settings.saved');
    }

    #[Route('/projects/{id}/sections/{sectionId}/move', name: 'app_project_section_move', requirements: ['id' => Requirement::UUID_V7, 'sectionId' => Requirement::UUID_V7], methods: ['POST'])]
    public function moveSection(string $id, string $sectionId, Request $request): RedirectResponse
    {
        $up = 'up' === $request->request->getString('direction');

        return $this->change($id, $request, function () use ($id, $sectionId, $up): void {
            [$at, $count] = $this->sectionAt($id, $sectionId);
            $to = $up ? $at - 1 : $at + 1;
            if ($to >= 0 && $to < $count) {
                $this->sections->move($sectionId, $to);
            }
        });
    }

    /**
     * Its tasks stay in the project, in no section.
     */
    #[Route('/projects/{id}/sections/{sectionId}/remove', name: 'app_project_section_remove', requirements: ['id' => Requirement::UUID_V7, 'sectionId' => Requirement::UUID_V7], methods: ['POST'])]
    public function removeSection(string $id, string $sectionId, Request $request): RedirectResponse
    {
        return $this->change($id, $request, function () use ($id, $sectionId): void {
            $this->sectionAt($id, $sectionId);
            $this->sections->remove($sectionId);
        });
    }

    /**
     * A new status at the end; an empty name adds nothing.
     */
    #[Route('/projects/{id}/statuses', name: 'app_project_status_add', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function addStatus(string $id, Request $request): RedirectResponse
    {
        $name = trim($request->request->getString('name'));
        $type = StatusType::tryFrom($request->request->getString('type'));

        return $this->change($id, $request, function () use ($id, $name, $type): void {
            if ('' !== $name) {
                $this->workflow->add($id, $name, $type ?? throw new WorkRefused('unknown'));
            }
        });
    }

    /**
     * The name and the type of a status, saved together.
     */
    #[Route('/projects/{id}/statuses/{statusId}', name: 'app_project_status_save', requirements: ['id' => Requirement::UUID_V7, 'statusId' => Requirement::UUID_V7], methods: ['POST'])]
    public function saveStatus(string $id, string $statusId, Request $request): RedirectResponse
    {
        $name = trim($request->request->getString('name'));
        $type = StatusType::tryFrom($request->request->getString('type'));

        return $this->change($id, $request, function () use ($id, $statusId, $name, $type): void {
            [$status] = $this->statusAt($id, $statusId);
            if ($type !== $status->type) {
                $this->workflow->retype($statusId, $type ?? throw new WorkRefused('unknown'));
            }
            if ($name !== $status->name) {
                $this->workflow->rename($statusId, $name);
            }
        }, 'work.settings.saved');
    }

    #[Route('/projects/{id}/statuses/{statusId}/move', name: 'app_project_status_move', requirements: ['id' => Requirement::UUID_V7, 'statusId' => Requirement::UUID_V7], methods: ['POST'])]
    public function moveStatus(string $id, string $statusId, Request $request): RedirectResponse
    {
        $up = 'up' === $request->request->getString('direction');

        return $this->change($id, $request, function () use ($id, $statusId, $up): void {
            [, $at, $count] = $this->statusAt($id, $statusId);
            $to = $up ? $at - 1 : $at + 1;
            if ($to >= 0 && $to < $count) {
                $this->workflow->move($statusId, $to);
            }
        });
    }

    /**
     * Asks which status the tasks of the removed one move to: a dialog over the settings, or a page of its own.
     */
    #[Route('/projects/{id}/statuses/{statusId}/remove', name: 'app_project_status_remove', requirements: ['id' => Requirement::UUID_V7, 'statusId' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function removeStatus(string $id, string $statusId, Request $request): Response
    {
        $this->denyUnlessStaff();
        $settings = $this->load($id);
        [$status] = $this->statusAt($id, $statusId, $settings);
        $targets = [];
        foreach ($settings->statuses as $other) {
            if ($other->id !== $statusId) {
                $targets[$other->id] = $other->name;
            }
        }
        $target = (string) array_key_first($targets);
        $error = null;
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $target = $request->request->getString('target');
            try {
                $this->workflow->remove($statusId, 1 === preg_match(self::UUID, $target) ? $target : '00000000-0000-0000-0000-000000000000');
                $this->addFlash('success', $this->translator->trans('work.settings.status_removed', ['name' => $status->name]));

                return $this->back($id);
            } catch (WorkRefused $refused) {
                $error = $this->translator->trans('work.refused.'.$refused->reason);
            }
        }

        return $this->render('work/projects/remove_status.html.twig', [
            'project' => $settings->project,
            'status' => $status,
            'targets' => $targets,
            'target' => $target,
            'error' => $error,
        ], new Response(status: null === $error ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * Runs one change and goes back to the settings; a refusal is told there.
     *
     * @param callable(): mixed $change
     */
    private function change(string $id, Request $request, callable $change, ?string $done = null): RedirectResponse
    {
        $this->denyUnlessStaff();
        $this->checkToken($request);
        try {
            $change();
            if (null !== $done) {
                $this->addFlash('success', $this->translator->trans($done));
            }
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
        }

        return $this->back($id);
    }

    private function load(string $id): ProjectSettings
    {
        return $this->queries->projectSettings($id) ?? throw new NotFoundHttpException();
    }

    /**
     * Where a section of the project stands: its position and the number of sections.
     *
     * @return array{int, int}
     */
    private function sectionAt(string $projectId, string $sectionId): array
    {
        $ids = array_map(static fn (Choice $section): string => $section->id, $this->load($projectId)->sections);
        $at = array_search($sectionId, $ids, true);

        return \is_int($at) ? [$at, \count($ids)] : throw new NotFoundHttpException();
    }

    /**
     * A status of the project, its position and the number of statuses.
     *
     * @return array{StatusChoice, int, int}
     */
    private function statusAt(string $projectId, string $statusId, ?ProjectSettings $settings = null): array
    {
        $statuses = ($settings ?? $this->load($projectId))->statuses;
        foreach ($statuses as $at => $status) {
            if ($status->id === $statusId) {
                return [$status, $at, \count($statuses)];
            }
        }

        throw new NotFoundHttpException();
    }

    /**
     * @return array<string, string>
     */
    private function types(): array
    {
        $types = [];
        foreach (StatusType::cases() as $type) {
            $types[$type->value] = $this->translator->trans('work.settings.type.'.$type->value);
        }

        return $types;
    }

    private function back(string $id): RedirectResponse
    {
        return $this->redirectToRoute(self::ROUTE, ['id' => $id], Response::HTTP_SEE_OTHER);
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
            throw new AccessDeniedHttpException('Only the staff set up the projects of the organisation.');
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
