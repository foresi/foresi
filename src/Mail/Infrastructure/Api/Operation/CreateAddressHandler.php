<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Api\Operation;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Application\AddressWrites;
use Foresi\Mail\Application\CreateAddressInput;
use Foresi\Mail\Infrastructure\Api\MailJson;
use Foresi\Shared\Contract\Operation\OperationHandler;
use Foresi\Shared\Contract\Operation\OperationOutput;

/**
 * The one answer that holds the full address.
 */
final readonly class CreateAddressHandler implements OperationHandler
{
    public function __construct(private AddressWrites $writes, private InstanceClock $clock)
    {
    }

    public static function input(): string
    {
        return CreateAddressInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof CreateAddressInput);
        $issued = $this->writes->create($input);

        return new OperationOutput(
            MailJson::address($issued->view, $this->clock->timezone()) + ['address' => $issued->address],
            \sprintf('Created the %s address %s. The address is shown only now: copy it to where it is used.', $issued->view->purpose, $issued->view->label),
        );
    }
}
