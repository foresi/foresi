<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Contract\Field;

use Foresi\Shared\Contract\Field\RecordExtension;

/**
 * An entity of another module that adds custom fields to a deal (ADR 0045): its id
 * is the id of the deal, and each of its other properties has
 * Foresi\Shared\Contract\Field\CustomField. Changes of it show in the history of
 * the deal.
 */
interface DealExtension extends RecordExtension
{
    /** The record kind of its fields, as Foresi\Shared\Contract\Field\Fields names it. */
    public const string RECORD = 'deal';

    /**
     * The id of the deal the fields belong to.
     */
    public function dealId(): string;
}
