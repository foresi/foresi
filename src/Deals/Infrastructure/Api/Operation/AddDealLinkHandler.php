<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Api\Operation;

use Foresi\Deals\Application\Api\AddDealLinkInput;
use Foresi\Deals\Application\DealLinkWrites;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

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
