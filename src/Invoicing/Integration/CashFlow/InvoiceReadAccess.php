<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Integration\CashFlow;

use App\Identity\Application\ApiTokenContext;
use App\Identity\Application\ApiTokenGrant;
use App\Identity\Domain\ApiScope;

/**
 * Whether the caller may read the invoices behind the forecast: anyone signed
 * in on the screens, and an API token with invoices:read (C15).
 */
final readonly class InvoiceReadAccess
{
    public function __construct(private ApiTokenContext $tokens)
    {
    }

    public function mayRead(): bool
    {
        $grant = $this->tokens->grant();

        return !$grant instanceof ApiTokenGrant || $grant->allows(ApiScope::of('invoices', false));
    }
}
