<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Vat\Application\PeriodWrites;
use Foresi\Vat\Application\SetReturnAmountInput;
use Foresi\Vat\Infrastructure\Api\VatJson;

final readonly class SetReturnAmountHandler implements OperationHandler
{
    public function __construct(private PeriodWrites $writes)
    {
    }

    public static function input(): string
    {
        return SetReturnAmountInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SetReturnAmountInput);
        $result = $this->writes->setReturnAmount($input);

        return new OperationOutput(VatJson::detail($result), \sprintf('Entered the VAT return amount of %s.', $result->period->period->key));
    }
}
