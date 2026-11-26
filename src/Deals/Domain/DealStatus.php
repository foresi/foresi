<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Domain;

/**
 * Where a deal stands: still being worked on, won or lost. Only an open deal
 * moves between stages; a won or lost one is reopened first.
 */
enum DealStatus: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';
}
