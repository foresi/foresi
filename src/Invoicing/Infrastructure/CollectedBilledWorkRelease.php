<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure;

use Foresi\Invoicing\Application\BilledWorkRelease;
use Foresi\Invoicing\Contract\BilledWorkRefused;
use Foresi\Invoicing\Contract\BilledWorkRelease as ModuleBilledWorkRelease;
use Foresi\Invoicing\Domain\Invoice;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tells every installed module whose work invoices bill, such as Timesheet, by
 * the invoice's id. Each one ignores an invoice that billed none of its work;
 * with none installed, an invoice frees and reclaims nothing. A module's
 * refusal of a restore becomes a problem of the form.
 */
final readonly class CollectedBilledWorkRelease implements BilledWorkRelease
{
    /**
     * @param iterable<ModuleBilledWorkRelease> $providers
     */
    public function __construct(
        #[AutowireIterator(ModuleBilledWorkRelease::TAG)]
        private iterable $providers,
    ) {
    }

    public function released(Invoice $invoice): void
    {
        foreach ($this->providers as $provider) {
            $provider->released($invoice->id());
        }
    }

    public function reclaim(Invoice $invoice): void
    {
        foreach ($this->providers as $provider) {
            try {
                $provider->reclaim($invoice->id());
            } catch (BilledWorkRefused $refusal) {
                throw BilledWorkIssueOrigin::refused($refusal);
            }
        }
    }

    public function revised(Invoice $invoice, array $lines): void
    {
        foreach ($this->providers as $provider) {
            $provider->revised($invoice->id(), $lines);
        }
    }
}
