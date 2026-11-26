<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Api\Operation;

use Foresi\Party\Application\RemoveVatStatusInput;
use Foresi\Party\Application\VatStatusWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class RemoveVatStatusHandler implements OperationHandler
{
    public function __construct(private VatStatusWrites $writes)
    {
    }

    public static function input(): string
    {
        return RemoveVatStatusInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RemoveVatStatusInput);

        return new OperationOutput(['id' => $this->writes->remove($input), 'removed' => true], 'Removed the VAT status; the one before it holds again.');
    }
}
