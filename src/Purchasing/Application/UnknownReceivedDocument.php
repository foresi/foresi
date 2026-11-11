<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

/**
 * No received document of the active tenant has the id; answered as not found.
 */
final class UnknownReceivedDocument extends \RuntimeException
{
}
