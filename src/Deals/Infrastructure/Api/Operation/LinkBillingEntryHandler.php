<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Api\Operation;

use Foresi\Deals\Application\Api\LinkBillingEntryInput;
use Foresi\Deals\Application\BillingPlanWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class LinkBillingEntryHandler implements OperationHandler
{
    public function __construct(private BillingPlanWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return LinkBillingEntryInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof LinkBillingEntryInput);

        return $this->answer->of($this->writes->link($input)->dealId(), 'Linked a planned invoice of the deal %s to its document.');
    }
}
