<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Work another module bills on a new invoice, such as time on projects. That
 * module prefills the invoice form, and its origin records what was billed in
 * the transaction that issues the invoice. The form carries the reference, and
 * every line it prefilled carries its source, so the module finds which line
 * bills what however the lines were edited. A module implements this and is
 * tagged automatically; a reference belongs to the module that recognises it.
 */
#[AutoconfigureTag(self::TAG)]
interface BilledWork
{
    public const string TAG = 'foresi.invoicing.billed_work';

    /**
     * What a new invoice opens with; null when the reference is not this module's or no longer holds.
     */
    public function draft(string $reference): ?BilledWorkDraft;

    /**
     * What records the work in the transaction that issues the invoice.
     *
     * @param list<?string> $sources the source each line of the invoice kept from the draft, in the order of the lines
     *
     * @throws BilledWorkRefused when the reference is not this module's or no longer holds
     */
    public function origin(string $reference, array $sources): BilledWorkOrigin;
}
