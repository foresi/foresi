<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

/**
 * The instance has no firm: its first-run setup has not happened yet.
 */
final class NoFirmYet extends \RuntimeException
{
}
