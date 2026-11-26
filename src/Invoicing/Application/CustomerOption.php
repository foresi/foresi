<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Party\Domain\Address;

/**
 * A contact as the invoice form offers it: the values of the firm where
 * it has its own. A contact without a billing address comes with an empty one, so
 * the save can say what is missing.
 */
final readonly class CustomerOption
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $registrationNumber,
        public ?string $vatId,
        public Address $billing,
        public InvoicingDefaults $defaults,
        public ?string $email,
    ) {
    }
}
