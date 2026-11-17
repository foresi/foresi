<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * No send has this id.
 */
final class UnknownInvoiceSend extends \RuntimeException
{
}
