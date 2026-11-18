<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking;

use Foresi\Banking\Application\ProcessInboundMessage;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;

/**
 * Bank accounts, their movements and the pairing of payments, with the bank
 * mail they arrive in.
 */
final class BankingBundle extends ModuleBundle
{
    protected function messageRouting(): array
    {
        return [
            // Inbound bank mail is processed after it was stored.
            ProcessInboundMessage::class => 'async',
        ];
    }
}
