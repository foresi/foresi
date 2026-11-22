<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Vat\Application\PeriodWrites;
use Foresi\Vat\Application\ResetPaymentInput;
use Foresi\Vat\Infrastructure\Api\VatJson;

final readonly class ResetPaymentHandler implements OperationHandler
{
    public function __construct(private PeriodWrites $writes)
    {
    }

    public static function input(): string
    {
        return ResetPaymentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ResetPaymentInput);
        $result = $this->writes->reset($input);

        return new OperationOutput(VatJson::assignment($result), 'The bank movement is matched automatically again.');
    }
}
