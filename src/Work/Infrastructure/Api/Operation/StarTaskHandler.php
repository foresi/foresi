<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\StarTaskInput;
use Foresi\Work\Application\ManageTaskStars;

/**
 * tasks.star: the task as it is now, starred.
 */
final readonly class StarTaskHandler implements OperationHandler
{
    public function __construct(private ManageTaskStars $stars, private WorkAnswers $answer)
    {
    }

    public static function input(): string
    {
        return StarTaskInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof StarTaskInput);
        $this->stars->star($input->taskId, $input->beforeTaskId);

        return $this->answer->task($input->taskId, 'Put the task %s on My priorities.');
    }
}
