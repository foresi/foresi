<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\BudgetWrites;
use Foresi\Work\Application\Api\RemoveProjectBudgetChangeInput;

/**
 * work.project_budget.remove_change: the budget without the change.
 */
final readonly class RemoveProjectBudgetChangeHandler implements OperationHandler
{
    public function __construct(private BudgetWrites $writes, private WorkAnswers $answer)
    {
    }

    public static function input(): string
    {
        return RemoveProjectBudgetChangeInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RemoveProjectBudgetChangeInput);
        $this->writes->remove($input);

        return $this->answer->budget($input->projectId, 'Removed the change from the budget of the project.');
    }
}
