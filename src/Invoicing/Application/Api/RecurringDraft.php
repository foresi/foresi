<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application\Api;

use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\PreviewRow;
use Foresi\Invoicing\Application\RecurringInput;

/**
 * A recurring invoice as a create would save it, previewed: nothing is stored.
 */
final readonly class RecurringDraft
{
    /**
     * @param list<PreviewRow>   $issues   the next invoices it would issue
     * @param list<IssueProblem> $problems what the rules would refuse; empty when it saves
     */
    public function __construct(
        public RecurringInput $input,
        public array $issues,
        public array $problems,
    ) {
    }
}
