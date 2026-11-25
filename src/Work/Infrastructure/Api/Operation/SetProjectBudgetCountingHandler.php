<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\BudgetWrites;
use Foresi\Work\Application\Api\SetProjectBudgetCountingInput;

/**
 * work.project_budget.set_counting: the budget as it now counts.
 */
final readonly class SetProjectBudgetCountingHandler implements OperationHandler
{
    public function __construct(private BudgetWrites $writes, private WorkAnswers $answer)
    {
    }

    public static function input(): string
    {
        return SetProjectBudgetCountingInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SetProjectBudgetCountingInput);
        $this->writes->count($input);

        return $this->answer->budget($input->projectId, 'Set which time uses the budget of the project.');
    }
}
