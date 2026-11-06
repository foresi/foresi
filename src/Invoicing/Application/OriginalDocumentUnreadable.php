<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * The original PDF of an imported document is missing, lies outside its tenant's
 * storage, or no longer matches the hash taken when it was imported.
 */
final class OriginalDocumentUnreadable extends \RuntimeException
{
}
