<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Vat\Application\ClearReturnAmountInput;
use Foresi\Vat\Application\PeriodWrites;
use Foresi\Vat\Infrastructure\Api\VatJson;

final readonly class ClearReturnAmountHandler implements OperationHandler
{
    public function __construct(private PeriodWrites $writes)
    {
    }

    public static function input(): string
    {
        return ClearReturnAmountInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ClearReturnAmountInput);
        $result = $this->writes->clearReturnAmount($input);

        return new OperationOutput(VatJson::detail($result), \sprintf('Cleared the VAT return amount of %s.', $result->period->period->key));
    }
}
