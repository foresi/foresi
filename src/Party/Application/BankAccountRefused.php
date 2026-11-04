<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/**
 * A change to a firm's bank account that the rules do not allow. Nothing was
 * written. The message is a translation key under "bank.account.refused".
 */
final class BankAccountRefused extends \DomainException
{
    public function __construct(public readonly BankAccountRefusal $reason)
    {
        parent::__construct('bank.account.refused.'.$reason->value);
    }
}
