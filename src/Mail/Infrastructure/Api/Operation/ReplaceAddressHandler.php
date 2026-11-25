<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Api\Operation;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Application\AddressWrites;
use Foresi\Mail\Application\ReplaceAddressInput;
use Foresi\Mail\Infrastructure\Api\MailJson;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

/**
 * The other answer that holds a full address: the new one.
 */
final readonly class ReplaceAddressHandler implements OperationHandler
{
    public function __construct(private AddressWrites $writes, private InstanceClock $clock)
    {
    }

    public static function input(): string
    {
        return ReplaceAddressInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof ReplaceAddressInput);
        $issued = $this->writes->replace($input);

        return new OperationOutput(
            MailJson::address($issued->view, $this->clock->timezone()) + ['address' => $issued->address],
            \sprintf('Replaced the %s address %s. The old address takes in nothing now; the new one is shown only now: copy it to where it is used.', $issued->view->purpose, $issued->view->label),
        );
    }
}
