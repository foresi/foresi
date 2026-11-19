<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Work\Application\Api\RestoreProjectInput;
use Foresi\Work\Application\Api\WorkWrites;

/**
 * projects.restore: the project, active again.
 */
final readonly class RestoreProjectHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes, private WorkAnswers $answer)
    {
    }

    public static function input(): string
    {
        return RestoreProjectInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RestoreProjectInput);

        return $this->answer->project($this->writes->restoreProject($input), 'Restored the project %s.');
    }
}
