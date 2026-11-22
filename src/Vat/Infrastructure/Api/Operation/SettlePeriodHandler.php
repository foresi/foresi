<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Vat\Application\PeriodWrites;
use Foresi\Vat\Application\SettlePeriodInput;
use Foresi\Vat\Infrastructure\Api\VatJson;

final readonly class SettlePeriodHandler implements OperationHandler
{
    public function __construct(private PeriodWrites $writes)
    {
    }

    public static function input(): string
    {
        return SettlePeriodInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SettlePeriodInput);
        $result = $this->writes->settle($input);

        return new OperationOutput(VatJson::detail($result), \sprintf('Marked the VAT period %s settled.', $result->period->period->key));
    }
}
