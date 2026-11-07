<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\DocumentKind;

/**
 * The months that have a document, for a month to be picked from them.
 */
interface DocumentMonths
{
    /**
     * The months, as YYYY-MM, with a document of the kind (of any kind when null) and of the
     * firm (of any firm when null), the trash included. A tax document belongs to the month
     * of its tax point, an advance to that of its issue, as InvoiceFilter narrows by month.
     *
     * @return list<string> newest first
     */
    public function months(?DocumentKind $kind, ?string $firmId): array;
}
