<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Integration\CashFlow;

use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;

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
