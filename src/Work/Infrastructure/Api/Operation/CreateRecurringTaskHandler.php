<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\CreateRecurringTaskInput;
use Foresi\Work\Application\ManageRecurringTasks;
use Foresi\Work\Contract\Field\TaskExtension;

/**
 * recurring-tasks.create: the recurring task as it is now.
 */
final readonly class CreateRecurringTaskHandler implements OperationHandler
{
    public function __construct(private ManageRecurringTasks $recurring, private RecurringTaskAnswer $answer, private CustomFieldPayload $customFields)
    {
    }

    public static function input(): string
    {
        return CreateRecurringTaskInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateRecurringTaskInput);

        return $this->answer->of($this->recurring->create($input, $this->customFields->read(TaskExtension::RECORD, $input->customFields, true)), 'Set up the recurring task %s.', true);
    }
}
