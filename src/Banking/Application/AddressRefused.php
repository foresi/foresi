<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Application;

/**
 * No inbound address could be handed out, and nothing was written. The message
 * is a translation key under "bank.account.address.refused".
 */
final class AddressRefused extends \DomainException
{
    public function __construct(public readonly AddressRefusal $reason)
    {
        parent::__construct('bank.account.address.refused.'.$reason->value);
    }
}
