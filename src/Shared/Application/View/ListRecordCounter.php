<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * How many records a view of a list holds, answered by the module that owns the
 * list with the same compiler its list uses (spec D9, D13).
 */
#[AutoconfigureTag(self::TAG)]
interface ListRecordCounter
{
    public const string TAG = 'app.list_record_counter';

    /**
     * @return list<string> the keys of the lists it counts, such as "invoicing.invoices"
     */
    public function lists(): array;

    /**
     * The scope a token needs to read the records, such as "invoices:read".
     */
    public function scope(): string;

    /**
     * @throws \DomainException when the person may not read the records
     */
    public function count(string $listKey, ViewFilter $filter): int;
}
