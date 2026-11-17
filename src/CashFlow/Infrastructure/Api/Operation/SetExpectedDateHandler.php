<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Api\Operation;

use App\CashFlow\Application\BalanceWrites;
use App\CashFlow\Application\SetExpectedDateInput;
use App\CashFlow\Infrastructure\Api\CashFlowJson;
use App\Shared\Infrastructure\Operation\OperationHandler;
use App\Shared\Infrastructure\Operation\OperationOutput;

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
