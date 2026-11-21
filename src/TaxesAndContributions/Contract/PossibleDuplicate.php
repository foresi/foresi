<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Contract;

/**
 * An active planned item of Cash flow in the category of a levy, which the
 * estimate flows may count a second time (B5). The owner ends it; the module
 * never does.
 *
 * @param string      $category taxes or insurance
 * @param string|null $url      where the owner opens it
 */
final readonly class PossibleDuplicate
{
    public function __construct(
        public string $id,
        public string $name,
        public string $category,
        public ?string $url = null,
    ) {
    }
}
