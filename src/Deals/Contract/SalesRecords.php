<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Contract;

/**
 * How other modules name the records of Sales, such as a link of a document
 * or an activity to a deal, and the subject of its events.
 */
final class SalesRecords
{
    public const string KIND = 'deal';
}
