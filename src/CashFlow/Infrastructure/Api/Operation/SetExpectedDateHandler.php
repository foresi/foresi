<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Api\Operation;

use Foresi\CashFlow\Application\BalanceWrites;
use Foresi\CashFlow\Application\SetExpectedDateInput;
use Foresi\CashFlow\Infrastructure\Api\CashFlowJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class SetExpectedDateHandler implements OperationHandler
{
    public function __construct(private BalanceWrites $writes)
    {
    }

    public static function input(): string
    {
        return SetExpectedDateInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof SetExpectedDateInput);
        $expected = $this->writes->expect($input);

        return new OperationOutput(CashFlowJson::expectedDate($expected->subjectType(), $expected->subjectId(), $expected), \sprintf('The %s is expected on %s.', $expected->subjectType(), $expected->expectedOn()->format('Y-m-d')));
    }
}
