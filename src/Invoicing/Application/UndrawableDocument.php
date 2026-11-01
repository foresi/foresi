<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * The values of a document do not fit its page at any size of type.
 */
final class UndrawableDocument extends \RuntimeException
{
}
