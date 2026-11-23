<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Api\Operation;

use Foresi\Sales\Application\Api\RemoveDealLinkInput;
use Foresi\Sales\Application\DealLinkWrites;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class RemoveDealLinkHandler implements OperationHandler
{
    public function __construct(private DealLinkWrites $writes, private DealAnswer $answer)
    {
    }

    public static function input(): string
    {
        return RemoveDealLinkInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RemoveDealLinkInput);

        return $this->answer->of($this->writes->remove($input)->id(), 'Removed a link of the deal %s.');
    }
}
