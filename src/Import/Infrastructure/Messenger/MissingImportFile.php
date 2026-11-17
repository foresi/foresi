<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Messenger;

/** The file of an import is gone, or its key is no key of the import store. */
final class MissingImportFile extends \RuntimeException
{
}
