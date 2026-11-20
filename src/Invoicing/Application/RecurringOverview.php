<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringInvoiceLine;

/**
 * What the list of recurring templates reads besides its page: how many each tab
 * holds, and the customers and lines of a page in one go, so the list takes no
 * query per row.
 */
interface RecurringOverview
{
    public const string ACTIVE = 'active';
    public const string PAUSED = 'paused';
    public const string ENDED = 'ended';
    public const string FAILING = 'failing';
    public const array TABS = [self::ACTIVE, self::PAUSED, self::ENDED, self::FAILING];

    /**
     * In one query. Failing counts the templates that have not ended and failed on
     * their last run, as RecurringInvoices::failing() does.
     *
     * @return array<string, int> by tab
     */
    public function counts(): array;

    /**
     * Loads the customers of the templates, so reading them asks nothing more, and
     * gives their lines, in two queries.
     *
     * @param list<RecurringInvoice> $templates
     *
     * @return array<string, list<RecurringInvoiceLine>> by template id, in the order of each template; every id present
     */
    public function prepare(array $templates): array;
}
