<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Infrastructure\Api\Operation;

use Foresi\Margin\Application\AllocationWrites;
use Foresi\Margin\Application\SetLineAllocationInput;
use Foresi\Margin\Infrastructure\Api\MarginJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * margin.line_allocations.set: the document with its lines and shares.
 */
final readonly class SetLineAllocationHandler implements OperationHandler
{
    public function __construct(private AllocationWrites $writes)
    {
    }

    public static function input(): string
    {
        return SetLineAllocationInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SetLineAllocationInput);

        return new OperationOutput(MarginJson::document($this->writes->setLine($input)), null === $input->kind ? 'Cleared the mapping of the line.' : 'Set the mapping of the line.');
    }
}
