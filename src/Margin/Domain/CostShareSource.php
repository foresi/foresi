<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Domain;

/**
 * Where a share got its recipient: the line's own mapping, the mapping of the
 * document, the paired plan item or the supplier's default (both from 34d),
 * or nowhere.
 */
enum CostShareSource: string
{
    case Line = 'line';
    case Document = 'document';
    case PlannedItem = 'planned_item';
    case Supplier = 'supplier';
    case Unassigned = 'unassigned';
}
