<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Controller;

use Foresi\Identity\Application\OpenToGuest;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\Task;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * New task from any page of Work (plan 37.1, D2 A): a dialog with the title and the
 * project it goes in, which every task has, then who is responsible, when it is due, its
 * priority and its labels, each optional. The project is picked by its name, those the
 * person last added a task to first, as Teamwork adds from My work and Linear's quick
 * create keeps the last team; it is prefilled from the page it opens from. The task
 * goes in no section, or the first the person sees when the project is not open to them.
 */
#[OpenToGuest]
final class TaskCreateController extends AbstractController
{
    public const string ROUTE = 'app_task_new';
    public const string TOKEN = 'work_task_quick';

    public function __construct(
        private readonly WorkQueries $queries,
        private readonly ManageTasks $tasks,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/tasks/new', name: self::ROUTE, methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $post = $request->isMethod('POST');
        $input = $post ? $request->request : $request->query;
        $return = $this->returnPath($input->getString('return'));
        $projects = $this->queries->taskableProjects(limit: 1);
        $values = [
            'title' => '',
            'project' => $input->getString('project'),
            'projectQuery' => '',
            'responsible' => '',
            // A day of the calendar opens it with its date (plan 37.5).
            'due' => $post ? '' : $input->getString('due'),
            'priority' => Priority::None->value,
            'labels' => [],
        ];
        $errors = [];
        if ($post) {
            if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            $values['title'] = trim($request->request->getString('title'));
            $values['projectQuery'] = trim($request->request->getString('projectQuery'));
            $values['responsible'] = $request->request->getString('responsible');
            $values['due'] = $request->request->getString('due');
            $values['priority'] = $request->request->getString('priority', Priority::None->value);
            $values['labels'] = array_values(array_filter($request->request->all('labels'), \is_string(...)));
            $errors = $this->checked($values);
            if ([] === $errors) {
                try {
                    $project = $this->queries->project($values['project']) ?? throw new WorkRefused('unknown');
                    $create = fn (?string $section): Task => $this->tasks->create(
                        $values['project'],
                        $values['title'],
                        $section,
                        responsibleId: '' === $values['responsible'] ? null : $values['responsible'],
                        dueOn: '' === $values['due'] ? null : new \DateTimeImmutable($values['due']),
                        priority: Priority::from($values['priority']),
                    );
                    try {
                        $task = $create(null);
                    } catch (WorkRefused $refused) {
                        // A project not open to the person takes a task in a section they see.
                        if ('unknown' !== $refused->reason || [] === $project->sections) {
                            throw $refused;
                        }
                        $task = $create($project->sections[0]->id);
                    }
                    if ([] !== $values['labels']) {
                        $this->tasks->setLabels($task->id(), $values['labels']);
                    }
                    $this->addFlash('success', $this->translator->trans('work.task.added', ['title' => $values['title']]));

                    return $this->redirect($return, Response::HTTP_SEE_OTHER);
                } catch (WorkRefused $refused) {
                    $errors[match ($refused->reason) {
                        'name' => 'title',
                        'responsible' => 'responsible',
                        'unknown', 'archived', 'not_allowed' => 'project',
                        default => 'form',
                    }] = $this->translator->trans('work.refused.'.$refused->reason);
                }
            }
        } elseif (!Id::isValid($values['project'])) {
            // The project the person last added a task to.
            $values['project'] = $projects[0]->id ?? '';
        }
        $chosen = Id::isValid($values['project']) ? $this->queries->project($values['project']) : null;
        if ($chosen instanceof \Foresi\Work\Application\ProjectDetail) {
            $values['projectQuery'] = $chosen->row->name;
        }

        return $this->render('@Work/tasks/new.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'return' => $return,
            'people' => $this->people($chosen?->row->id),
            'priorities' => array_combine(array_column(Priority::cases(), 'value'), array_map(fn (Priority $priority): string => $this->translator->trans('work.priority.'.$priority->value), Priority::cases())),
            'labels' => $this->queries->labels(),
            'hasProjects' => [] !== $projects,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, string>
     */
    private function checked(array $values): array
    {
        $errors = [];
        if ('' === $values['title']) {
            $errors['title'] = $this->translator->trans('work.task.error.title_required');
        }
        $project = \is_string($values['project']) ? $values['project'] : '';
        if (!Id::isValid($project) || !\in_array($project, array_map(static fn (ProjectRow $row): string => $row->id, $this->queries->taskableProjects(limit: 1000)), true)) {
            $errors['project'] = $this->translator->trans('work.tasks.new.project_required');
        }
        $due = \is_string($values['due']) ? $values['due'] : '';
        if ('' !== $due && false === \DateTimeImmutable::createFromFormat('!Y-m-d', $due)) {
            $errors['due'] = $this->translator->trans('work.tasks.new.due_invalid');
        }
        if (!Priority::tryFrom(\is_string($values['priority']) ? $values['priority'] : '') instanceof Priority) {
            $errors['priority'] = $this->translator->trans('work.tasks.new.priority_invalid');
        }

        return $errors;
    }

    /**
     * Who may be responsible: those who see the project, or everyone active until one is picked.
     *
     * @return array<string, string> names by membership id, nobody first
     */
    private function people(?string $projectId): array
    {
        $people = ['' => $this->translator->trans('work.task.nobody')];
        foreach (null === $projectId ? [] : ($this->queries->projectPeople($projectId) ?? []) as $person) {
            $people[$person->id] = $person->name;
        }

        return $people;
    }

    /**
     * A path of this installation to go back to; anything else is the list of all tasks.
     */
    private function returnPath(string $return): string
    {
        $parts = parse_url($return);
        if ('' === $return || !\is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || !str_starts_with($return, '/') || str_starts_with($return, '//')) {
            return $this->generateUrl(TaskListController::ROUTE);
        }

        return $return;
    }
}
