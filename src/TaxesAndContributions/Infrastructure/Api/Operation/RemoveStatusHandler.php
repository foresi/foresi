<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\TaxesAndContributions\Application\ProfileWrites;
use Foresi\TaxesAndContributions\Application\RemoveStatusInput;

final readonly class RemoveStatusHandler implements OperationHandler
{
    public function __construct(private ProfileWrites $writes)
    {
    }

    public static function input(): string
    {
        return RemoveStatusInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RemoveStatusInput);

        return new OperationOutput(['id' => $this->writes->removeStatus($input), 'removed' => true], 'Removed the status; the one before it holds again.');
    }
}
