<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

/**
 * No such contact in the active tenant: it never existed, or it belongs to
 * another tenant.
 */
final class UnknownParty extends \RuntimeException
{
}
