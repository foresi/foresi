<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Api\Operation;

use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;
use Foresi\Vat\Application\AssignPaymentInput;
use Foresi\Vat\Application\PeriodWrites;
use Foresi\Vat\Infrastructure\Api\VatJson;

final readonly class AssignPaymentHandler implements OperationHandler
{
    public function __construct(private PeriodWrites $writes)
    {
    }

    public static function input(): string
    {
        return AssignPaymentInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AssignPaymentInput);
        $result = $this->writes->assign($input);

        return new OperationOutput(VatJson::assignment($result), null === $result->period ? 'Marked the bank movement as no VAT payment.' : \sprintf('Assigned the bank movement to the VAT period %s.', $result->period));
    }
}
