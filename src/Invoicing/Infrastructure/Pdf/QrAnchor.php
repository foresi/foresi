<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

/**
 * What the position of a QR figure is measured from.
 */
enum QrAnchor
{
    /** The first page, on a page whose body starts at the top margin. */
    case Body;
    /** The top of the block after the body, wherever it lands. */
    case Foot;
}
