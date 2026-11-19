<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Work\Application\Api\CreateProjectInput;
use Foresi\Work\Application\Api\WorkWrites;

/**
 * projects.create: the new project.
 */
final readonly class CreateProjectHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes, private WorkAnswers $answer)
    {
    }

    public static function input(): string
    {
        return CreateProjectInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateProjectInput);

        return $this->answer->project($this->writes->createProject($input), 'Created the project %s.', true);
    }
}
