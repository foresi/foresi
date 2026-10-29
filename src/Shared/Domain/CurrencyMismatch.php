<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Raised when an operation combines amounts of different currencies.
 */
final class CurrencyMismatch extends \DomainException
{
}
