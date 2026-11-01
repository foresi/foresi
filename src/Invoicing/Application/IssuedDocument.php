<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * A read-only view of an issued invoice, its lines and rate summaries, for the
 * PDF and the country code. A placeholder here: the aggregate step gives it its
 * content, and the country methods that take it are filled by the steps after.
 */
final readonly class IssuedDocument
{
}
