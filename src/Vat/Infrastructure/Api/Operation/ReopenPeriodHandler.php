<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Vat\Application\PeriodWrites;
use Foresi\Vat\Application\ReopenPeriodInput;
use Foresi\Vat\Infrastructure\Api\VatJson;

final readonly class ReopenPeriodHandler implements OperationHandler
{
    public function __construct(private PeriodWrites $writes)
    {
    }

    public static function input(): string
    {
        return ReopenPeriodInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ReopenPeriodInput);
        $result = $this->writes->reopen($input);

        return new OperationOutput(VatJson::detail($result), \sprintf('Reopened the VAT period %s.', $result->period->period->key));
    }
}
