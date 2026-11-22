<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Contract;

/**
 * An active planned item of Cash flow in the category vat, which the flows
 * of the VAT periods may count a second time. A person ends it; the module
 * never does.
 *
 * @param string|null $url where a person opens it
 */
final readonly class PossibleDuplicate
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $url = null,
    ) {
    }
}
