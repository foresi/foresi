<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure;

use Foresi\Work\Contract\InvoiceNumbers;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The invoice numbers every installed module that bills time knows. With no
 * such module, a line of time names no invoice.
 */
final readonly class CollectedInvoiceNumbers implements InvoiceNumbers
{
    /**
     * @param iterable<InvoiceNumbers> $providers
     */
    public function __construct(
        #[AutowireIterator(InvoiceNumbers::TAG)]
        private iterable $providers,
    ) {
    }

    public function numbersOf(array $invoiceIds): array
    {
        $numbers = [];
        foreach ($this->providers as $provider) {
            $numbers += $provider->numbersOf($invoiceIds);
        }

        return $numbers;
    }
}
