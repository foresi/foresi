<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * The tabs of the firm after its details, filled by the modules that keep settings of the firm.
 * A provider answers the Ui slot SLOT with items whose data holds "key", "label" and "href";
 * the context holds "firmId".
 */
final class FirmTabs
{
    public const string SLOT = 'party.firm_tabs';
}
