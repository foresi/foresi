<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealLink;

/**
 * A link of a deal as the deal shows it: the other deal, and whether this
 * deal follows from it ($outgoing) or it follows from this one.
 */
final readonly class DealLinkView
{
    public function __construct(
        public DealLink $link,
        public Deal $other,
        public bool $outgoing,
    ) {
    }
}
