<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure;

use Foresi\Invoicing\Application\BilledWorkRelease;
use Foresi\Invoicing\Domain\Invoice;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tells every installed module whose work invoices bill, such as Timesheet.
 * Each one ignores an invoice that billed none of its work; with none
 * installed, an invoice frees and reclaims nothing.
 */
final readonly class CollectedBilledWorkRelease implements BilledWorkRelease
{
    /**
     * @param iterable<BilledWorkRelease> $providers
     */
    public function __construct(
        #[AutowireIterator(BilledWorkRelease::TAG)]
        private iterable $providers,
    ) {
    }

    public function released(Invoice $invoice): void
    {
        foreach ($this->providers as $provider) {
            $provider->released($invoice);
        }
    }

    public function reclaim(Invoice $invoice): void
    {
        foreach ($this->providers as $provider) {
            $provider->reclaim($invoice);
        }
    }

    public function revised(Invoice $invoice, array $lines): void
    {
        foreach ($this->providers as $provider) {
            $provider->revised($invoice, $lines);
        }
    }
}
