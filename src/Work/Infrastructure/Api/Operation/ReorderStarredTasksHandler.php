<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\ReorderStarredTasksInput;
use Foresi\Work\Application\ManageTaskStars;
use Foresi\Work\Application\WorkRefused;

/**
 * tasks.starred.reorder: the ids of My priorities in their new order.
 */
final readonly class ReorderStarredTasksHandler implements OperationHandler
{
    public function __construct(private ManageTaskStars $stars)
    {
    }

    public static function input(): string
    {
        return ReorderStarredTasksInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ReorderStarredTasksInput);
        $ids = array_values(array_filter($input->taskIds, \is_string(...)));
        if (\count($ids) !== \count($input->taskIds)) {
            throw new WorkRefused('star_order');
        }
        $this->stars->reorder($ids);

        return new OperationOutput(['task_ids' => $ids], 'Put My priorities in their new order.');
    }
}
