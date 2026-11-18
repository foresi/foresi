<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Support;

use Foresi\Invoicing\Application\InvoiceBalances;

/**
 * Balances that cannot tell a paid invoice from an open one, as a module binding
 * them without payments would: they answer what the inner ones say and are not
 * authoritative, which keeps automatic reminders off.
 */
final readonly class UnauthoritativeBalances implements InvoiceBalances
{
    public function __construct(private InvoiceBalances $inner)
    {
    }

    public function outstanding(array $invoiceIds): array
    {
        return $this->inner->outstanding($invoiceIds);
    }

    public function isAuthoritative(): bool
    {
        return false;
    }
}
