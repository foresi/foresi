<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Migration;

/**
 * Where a database stands with its migrations (ADR 0034).
 */
enum RebaseState: string
{
    /** It has executed no migration. */
    case Fresh = 'fresh';
    /** It has executed every global version and nothing else: it can move onto the baselines. */
    case Global = 'global';
    /** It has executed no global version: it is on the baselines. */
    case Rebased = 'rebased';
    /** It has executed some global versions, or global versions and others. */
    case Mixed = 'mixed';
}
