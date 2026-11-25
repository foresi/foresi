<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\AddProjectBudgetChangeInput;
use Foresi\Work\Application\Api\BudgetWrites;
use Foresi\Work\Infrastructure\Api\ProjectApiController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * work.project_budget.add_change: the budget with the new change.
 */
final readonly class AddProjectBudgetChangeHandler implements OperationHandler
{
    public function __construct(private BudgetWrites $writes, private WorkAnswers $answer, private UrlGeneratorInterface $urls)
    {
    }

    public static function input(): string
    {
        return AddProjectBudgetChangeInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AddProjectBudgetChangeInput);
        $this->writes->add($input);

        return $this->answer->budget($input->projectId, 'Added the change to the budget of the project.', $this->urls->generate(ProjectApiController::BUDGET, ['projectId' => $input->projectId]));
    }
}
