<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Api\Operation;

use Foresi\Shared\Application\Field\CustomFieldPayload;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;
use Foresi\Work\Application\Api\UpdateProjectInput;
use Foresi\Work\Application\Api\WorkWrites;
use Foresi\Work\Contract\Field\ProjectExtension;

/**
 * projects.update: the project as it is now.
 */
final readonly class UpdateProjectHandler implements OperationHandler
{
    public function __construct(private WorkWrites $writes, private WorkAnswers $answer, private CustomFieldPayload $customFields)
    {
    }

    public static function input(): string
    {
        return UpdateProjectInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateProjectInput);

        return $this->answer->project($this->writes->updateProject($input, $this->customFields->read(ProjectExtension::RECORD, $input->customFields, false)), 'Updated the project %s.');
    }
}
