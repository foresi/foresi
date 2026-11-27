<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Contract;

/**
 * What fulfils a planned invoice of a won deal: an issued invoice, or a
 * recurring invoice that issues them. Since module API 1.8.
 */
enum BillingDocumentType: string
{
    case Invoice = 'invoice';
    case RecurringInvoice = 'recurring_invoice';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
