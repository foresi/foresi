<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\UnlinkBillingEntryInput;
use Foresi\Sales\Application\BillingPlanWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class UnlinkBillingEntryHandler implements OperationHandler
{
    public function __construct(private BillingPlanWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return UnlinkBillingEntryInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UnlinkBillingEntryInput);

        return $this->answer->of($this->writes->unlink($input)->dealId(), 'Unlinked a planned invoice of the deal %s from its document.');
    }
}
