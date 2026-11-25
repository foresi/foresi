<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Api\Operation;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Application\AddressWrites;
use Foresi\Mail\Application\RevokeAddressInput;
use Foresi\Mail\Infrastructure\Api\MailJson;
use Foresi\Shared\Infrastructure\Operation\OperationHandler;
use Foresi\Shared\Infrastructure\Operation\OperationOutput;

final readonly class RevokeAddressHandler implements OperationHandler
{
    public function __construct(private AddressWrites $writes, private InstanceClock $clock)
    {
    }

    public static function input(): string
    {
        return RevokeAddressInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof RevokeAddressInput);
        $view = $this->writes->revoke($input);

        return new OperationOutput(MailJson::address($view, $this->clock->timezone()), \sprintf('Revoked the address %s.', $view->label));
    }
}
