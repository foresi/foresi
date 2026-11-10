<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;

/**
 * Other documents of a firm that look like the same one, outside the trash,
 * oldest first. They only warn: a supplier may send the same file twice for a
 * reason, and a number may repeat across years.
 */
interface DuplicateDocuments
{
    /**
     * The documents whose original file has these bytes.
     *
     * @return list<string> their ids
     */
    public function withFile(LegalEntity $firm, string $sha256, string $exceptId): array;

    /**
     * The recorded documents of the supplier with this number of theirs, in any case.
     *
     * @return list<string> their ids
     */
    public function withSupplierNumber(LegalEntity $firm, Party $supplier, string $supplierNumber, string $exceptId): array;
}
