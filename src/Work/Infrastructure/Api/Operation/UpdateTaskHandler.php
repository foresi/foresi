<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\UpdateTaskInput;
use Foresi\Work\Application\Api\WorkWrites;
use Foresi\Work\Contract\Field\TaskExtension;

/**
 * tasks.update: the task as it is now.
 */
final readonly class UpdateTaskHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes, private WorkAnswers $answer, private CustomFieldPayload $customFields)
    {
    }

    public static function input(): string
    {
        return UpdateTaskInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateTaskInput);

        return $this->answer->task($this->writes->updateTask($input, $this->customFields->read(TaskExtension::RECORD, $input->customFields, false)), 'Updated the task %s.');
    }
}
