<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Messenger;

/** The file of an import is gone, or its key does not belong to the active tenant. */
final class MissingImportFile extends \RuntimeException
{
}
