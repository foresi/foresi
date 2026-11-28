<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Domain;

/**
 * ISO 4217 has no currency of the given code.
 */
final class UnknownCurrency extends \InvalidArgumentException
{
}
