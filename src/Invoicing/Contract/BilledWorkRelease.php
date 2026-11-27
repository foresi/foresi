<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Told when an invoice goes to the trash and when it comes back, and when the
 * lines of an issued invoice are edited, inside the same transaction, so the
 * module whose work the invoice billed frees that work, bills it again or keeps
 * it with its line. Each module ignores an invoice that billed none of its
 * work. A module implements this and is tagged automatically.
 */
#[AutoconfigureTag(self::TAG)]
interface BilledWorkRelease
{
    public const string TAG = 'foresi.invoicing.billed_work_release';

    /**
     * The invoice is in the trash already.
     */
    public function released(string $invoiceId): void;

    /**
     * The invoice is restored already: the work released bills on it again,
     * exactly as it did; work billed elsewhere or changed meanwhile refuses the restore.
     *
     * @throws BilledWorkRefused when some of the work was billed elsewhere, changed or deleted since
     */
    public function reclaim(string $invoiceId): void;

    /**
     * The lines of the issued invoice were edited and saved. A line keeps its
     * position, so a row moved or removed in the form leaves its content on
     * another line: each line id of the map is where a row was, and its value the
     * line the row is on now, or null once the row is gone. The work billed on a
     * row goes with it, and the work of a row removed is free again.
     *
     * @param array<string, ?string> $lines by the id of the line a row was on, the id of the line it is on now, or null
     */
    public function revised(string $invoiceId, array $lines): void;
}
