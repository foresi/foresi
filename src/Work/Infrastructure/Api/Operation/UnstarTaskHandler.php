<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\UnstarTaskInput;
use Foresi\Work\Application\ManageTaskStars;

/**
 * tasks.unstar: the task as it is now, with no star of the person.
 */
final readonly class UnstarTaskHandler implements OperationHandler
{
    public function __construct(private ManageTaskStars $stars, private WorkAnswers $answer)
    {
    }

    public static function input(): string
    {
        return UnstarTaskInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UnstarTaskInput);
        $this->stars->unstar($input->taskId);

        return $this->answer->task($input->taskId, 'Took the task %s off My priorities.');
    }
}
