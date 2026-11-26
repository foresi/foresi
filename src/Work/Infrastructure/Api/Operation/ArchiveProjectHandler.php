<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\ArchiveProjectInput;
use Foresi\Work\Application\Api\WorkWrites;

/**
 * projects.archive: the archived project.
 */
final readonly class ArchiveProjectHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes, private WorkAnswers $answer)
    {
    }

    public static function input(): string
    {
        return ArchiveProjectInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ArchiveProjectInput);

        return $this->answer->project($this->writes->archiveProject($input), 'Archived the project %s.');
    }
}
