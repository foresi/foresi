<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Api\Operation;

use Foresi\Deals\Application\Api\CreateBillingEntryInput;
use Foresi\Deals\Application\BillingPlanWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class CreateBillingEntryHandler implements OperationHandler
{
    public function __construct(private BillingPlanWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return CreateBillingEntryInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateBillingEntryInput);

        return $this->answer->of($this->writes->create($input)->dealId(), 'Planned an invoice of the deal %s.');
    }
}
