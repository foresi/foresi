<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Port: hands one recurring template to the worker, which issues and sends what
 * it has due (IssueRecurringTemplate). Called after the template is committed.
 */
interface RecurringIssueQueue
{
    public function issue(string $templateId): void;
}
