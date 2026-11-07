<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Identity\Application\StaffCheck;
use App\Work\Application\WorkQueries;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The page of a task, for the staff: for now what it is, where it belongs and
 * its status, to read.
 */
final class TaskController extends AbstractController
{
    public const string ROUTE = 'app_task';

    public function __construct(private readonly WorkQueries $queries, private readonly StaffCheck $staff)
    {
    }

    #[Route('/tasks/{id}', name: self::ROUTE, requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function show(string $id): Response
    {
        if (!$this->staff->isStaff()) {
            throw new AccessDeniedHttpException('Only the staff work on the tasks of the organisation.');
        }
        $task = $this->queries->task($id) ?? throw new NotFoundHttpException();

        return $this->render('work/task/show.html.twig', [
            'task' => $task,
            'statusTone' => ['open' => 'gray', 'active' => 'amber', 'done' => 'green', 'closed' => 'gray'][$task->task->statusType->value],
        ]);
    }
}
