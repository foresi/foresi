<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\AddDealLinkInput;
use Foresi\Sales\Application\DealLinkWrites;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class AddDealLinkHandler implements OperationHandler
{
    public function __construct(private DealLinkWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return AddDealLinkInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AddDealLinkInput);

        return $this->answer->of($this->writes->add($input)->fromDealId(), 'Linked the deal %s to another deal.');
    }
}
