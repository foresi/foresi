<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\CreateFollowUpDealInput;
use Foresi\Sales\Application\DealWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

final readonly class CreateFollowUpDealHandler implements OperationHandler
{
    public function __construct(private DealWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return CreateFollowUpDealInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateFollowUpDealInput);

        return $this->answer->of($this->writes->createFollowUp($input)->id(), 'Opened the follow-up deal %s.', true);
    }
}
