<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Api\Operation;

use Foresi\Margin\Application\AllocationWrites;
use Foresi\Margin\Application\AssignDocumentInput;
use Foresi\Margin\Infrastructure\Api\MarginJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * margin.documents.assign: the document with its lines and shares.
 */
final readonly class AssignDocumentHandler implements OperationHandler
{
    public function __construct(private AllocationWrites $writes)
    {
    }

    public static function input(): string
    {
        return AssignDocumentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AssignDocumentInput);

        return new OperationOutput(MarginJson::document($this->writes->assign($input)), null === $input->recipient ? 'Cleared the assignment of the document.' : 'Assigned the document.');
    }
}
