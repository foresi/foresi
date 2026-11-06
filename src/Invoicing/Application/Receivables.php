<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * What the customers of the active tenant still owe, for the overview.
 */
interface Receivables
{
    /**
     * Name by id of every firm with a document, the trash included, as the list has them.
     *
     * @return array<string, string>
     */
    public function firms(): array;

    /**
     * The invoices and advances out of the trash with something still to pay after
     * their corrections and payments, as the unpaid tab of the list has them.
     *
     * @param string|null $firmId null for all firms
     *
     * @return list<Receivable>
     */
    public function open(?string $firmId): array;
}
