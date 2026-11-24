<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

use Foresi\Purchasing\Contract\Reading\Reading;

/**
 * Finds the supplier of a reading among the active parties: by registration
 * number first, then by VAT id. Exactly one match is the supplier; none gives
 * supplier_not_found and more than one supplier_ambiguous, and neither
 * guesses.
 */
interface SupplierMatching
{
    public function match(Reading $reading): SupplierMatch;
}
