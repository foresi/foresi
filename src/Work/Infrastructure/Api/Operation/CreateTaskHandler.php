<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\CreateTaskInput;
use Foresi\Work\Application\Api\WorkWrites;
use Foresi\Work\Contract\Field\TaskExtension;

/**
 * tasks.create: the new task.
 */
final readonly class CreateTaskHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes, private WorkAnswers $answer, private CustomFieldPayload $customFields)
    {
    }

    public static function input(): string
    {
        return CreateTaskInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateTaskInput);

        return $this->answer->task($this->writes->createTask($input, $this->customFields->read(TaskExtension::RECORD, $input->customFields, true)), 'Created the task %s.', true);
    }
}
