<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Tenancy\Application\TenantScopedMessage;

/**
 * Issues and sends what one recurring template has due, as the daily run would,
 * in the tenant the message carries: for a template saved or resumed after the
 * day's run had finished. The occurrence key issues each date once, so a second
 * message, or the run itself, issues nothing more.
 */
final readonly class IssueRecurringTemplate implements TenantScopedMessage
{
    public function __construct(public string $templateId)
    {
    }
}
