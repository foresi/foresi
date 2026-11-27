<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\UpdateRecurringTaskInput;
use Foresi\Work\Application\ManageRecurringTasks;
use Foresi\Work\Contract\Field\TaskExtension;

/**
 * recurring-tasks.update: the recurring task as it is now.
 */
final readonly class UpdateRecurringTaskHandler implements OperationHandler
{
    public function __construct(private ManageRecurringTasks $recurring, private RecurringTaskAnswer $answer, private CustomFieldPayload $customFields)
    {
    }

    public static function input(): string
    {
        return UpdateRecurringTaskInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateRecurringTaskInput);

        return $this->answer->of($this->recurring->update($input, $this->customFields->read(TaskExtension::RECORD, $input->customFields, false)), 'Changed the recurring task %s; the tasks to come follow it.');
    }
}
