<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

/**
 * What the customers still owe, for the overview.
 */
interface Receivables
{
    /**
     * Whether any document exists, the trash included.
     */
    public function hasDocuments(): bool;

    /**
     * The invoices and advances out of the trash with something still to pay after
     * their corrections and payments, as the unpaid tab of the list has them.
     *
     * @return list<Receivable>
     */
    public function open(): array;
}
