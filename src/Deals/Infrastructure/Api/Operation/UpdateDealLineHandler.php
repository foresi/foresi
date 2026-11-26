<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Api\Operation;

use Foresi\Deals\Application\Api\UpdateDealLineInput;
use Foresi\Deals\Application\DealLineWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class UpdateDealLineHandler implements OperationHandler
{
    public function __construct(private DealLineWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return UpdateDealLineInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof UpdateDealLineInput);

        return $this->answer->of($this->writes->update($input)->dealId(), 'Updated a line of the deal %s.');
    }
}
