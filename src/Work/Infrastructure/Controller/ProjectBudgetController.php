<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Controller;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Work\Application\BudgetHours;
use Foresi\Work\Application\ProjectBudgetReads;
use Foresi\Work\Application\ProjectBudgets;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Application\WorkVisibility;
use Foresi\Work\Domain\BudgetCounting;
use Foresi\Work\Infrastructure\Slot\ProjectBudgetFacts;
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
 * The dialogs of a project's Budget tab: a new change, which time counts, and
 * removing a wrong change. Each goes back to the Budget tab.
 */
final class ProjectBudgetController extends AbstractController
{
    public const string NEW_ROUTE = 'app_project_budget_change_new';
    public const string COUNTING_ROUTE = 'app_project_budget_counting';
    public const string REMOVE_ROUTE = 'app_project_budget_change_remove';
    public const string TOKEN = 'work_project_budget';

    public function __construct(
        private readonly ProjectBudgets $budgets,
        private readonly ProjectBudgetReads $reads,
        private readonly WorkQueries $queries,
        private readonly WorkVisibility $visibility,
        private readonly InstanceClock $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/projects/{id}/budget/changes/new', name: self::NEW_ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function new(string $id, Request $request): Response
    {
        $project = $this->editable($id);
        $values = ['hours' => '', 'effectiveOn' => $this->clock->today()->format('Y-m-d'), 'note' => ''];
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $values = [
                'hours' => trim($request->request->getString('hours')),
                'effectiveOn' => trim($request->request->getString('effectiveOn')),
                'note' => trim($request->request->getString('note')),
            ];
            $minutes = BudgetHours::minutes($values['hours']);
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $values['effectiveOn']);
            if (!$day instanceof \DateTimeImmutable || $day->format('Y-m-d') !== $values['effectiveOn'] || $day < new \DateTimeImmutable('1900-01-01')) {
                $errors['effectiveOn'] = $this->translator->trans('work.refused.date');
                $day = null;
            }
            if (null === $minutes) {
                $errors['hours'] = $this->translator->trans('work.refused.hours_invalid');
            } elseif ($day instanceof \DateTimeImmutable) {
                try {
                    $this->budgets->add($id, $minutes, $day, $values['note']);
                    $this->addFlash('success', $this->translator->trans('work.budget.added'));

                    return $this->back($id);
                } catch (WorkRefused $refused) {
                    $this->notFoundIfUnknown($refused);
                    $errors[match ($refused->reason) {
                        'hours_invalid', 'hours_zero' => 'hours',
                        'budget_note' => 'note',
                        default => 'form',
                    }] = $this->translator->trans('work.refused.'.$refused->reason);
                }
            }
        }

        return $this->render('@Work/projects/budget_change_new.html.twig', [
            'project' => $project,
            'values' => $values,
            'errors' => $errors,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/projects/{id}/budget/counting', name: self::COUNTING_ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function counting(string $id, Request $request): Response
    {
        $project = $this->editable($id);
        $counting = $this->reads->counting($id);
        $errors = [];
        if ($request->isMethod('POST')) {
            $this->checkToken($request);
            $chosen = BudgetCounting::tryFrom($request->request->getString('counting'));
            if (!$chosen instanceof BudgetCounting) {
                $errors['counting'] = $this->translator->trans('work.refused.budget_counting');
            } else {
                try {
                    $this->budgets->count($id, $chosen);
                    $this->addFlash('success', $this->translator->trans('work.budget.counting.saved'));

                    return $this->back($id);
                } catch (WorkRefused $refused) {
                    $this->notFoundIfUnknown($refused);
                    $errors['form'] = $this->translator->trans('work.refused.'.$refused->reason);
                }
            }
        }

        return $this->render('@Work/projects/budget_counting.html.twig', [
            'project' => $project,
            'counting' => $counting->value,
            'options' => [
                ['value' => BudgetCounting::All->value, 'label' => $this->translator->trans('work.budget.counting.all'), 'text' => $this->translator->trans('work.budget.counting.all_help')],
                ['value' => BudgetCounting::Billable->value, 'label' => $this->translator->trans('work.budget.counting.billable'), 'text' => $this->translator->trans('work.budget.counting.billable_help')],
            ],
            'errors' => $errors,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/projects/{id}/budget/changes/{changeId}/remove', name: self::REMOVE_ROUTE, requirements: ['id' => Requirement::UUID_V7, 'changeId' => Requirement::UUID_V7], methods: ['POST'])]
    public function remove(string $id, string $changeId, Request $request): RedirectResponse
    {
        $this->checkToken($request);
        try {
            $this->budgets->remove($id, $changeId);
            $this->addFlash('success', $this->translator->trans('work.budget.removed'));
        } catch (WorkRefused $refused) {
            $this->notFoundIfUnknown($refused);
            $this->addFlash('error', $this->translator->trans('work.refused.'.$refused->reason));
        }

        return $this->back($id);
    }

    /**
     * A project the person may change the budget of: of the organisation's people, not archived.
     */
    private function editable(string $id): ProjectRow
    {
        $project = $this->queries->project($id)->row ?? throw new NotFoundHttpException();
        if (!$this->visibility->canManage()) {
            throw new NotFoundHttpException();
        }
        if ($project->archived) {
            throw new NotFoundHttpException('The project is archived.');
        }

        return $project;
    }

    private function back(string $id): RedirectResponse
    {
        return $this->redirectToRoute(ProjectController::PROJECT_ROUTE, ['id' => $id, 'tab' => ProjectBudgetFacts::KEY], Response::HTTP_SEE_OTHER);
    }

    private function notFoundIfUnknown(WorkRefused $refused): void
    {
        if ('unknown' === $refused->reason) {
            throw new NotFoundHttpException($refused->getMessage(), $refused);
        }
    }

    private function checkToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
