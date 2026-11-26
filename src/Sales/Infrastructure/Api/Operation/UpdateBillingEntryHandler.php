<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\UpdateBillingEntryInput;
use Foresi\Sales\Application\BillingPlanWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class UpdateBillingEntryHandler implements OperationHandler
{
    public function __construct(private BillingPlanWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return UpdateBillingEntryInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateBillingEntryInput);

        return $this->answer->of($this->writes->update($input)->dealId(), 'Changed a planned invoice of the deal %s.');
    }
}
