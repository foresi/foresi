<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\AddDealLineInput;
use Foresi\Sales\Application\DealLineWrites;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class AddDealLineHandler implements OperationHandler
{
    public function __construct(private DealLineWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return AddDealLineInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AddDealLineInput);

        return $this->answer->of($this->writes->add($input)->dealId(), 'Added a line to the deal %s.');
    }
}
