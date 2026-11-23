<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Contract;

/**
 * What Cash flow refuses a screen that pairs or unpairs a document. The message is in English and meant for a log;
 * a screen words its own for the person, by the reason.
 */
final class PairingRefused extends \DomainException
{
    public function __construct(public readonly string $reason, string $message, public readonly bool $forbidden = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
