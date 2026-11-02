<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Dialog;

use Symfony\Component\HttpFoundation\Request;

/**
 * A request the dialog controller sent: it wants the page's dialog alone, not
 * the whole page. The same URL without the header is the full page, which is
 * what a link opens without JavaScript, in a new tab or from a bookmark.
 */
final class DialogRequest
{
    public const string HEADER = 'X-Foresi-Dialog';

    public static function is(Request $request): bool
    {
        return '1' === $request->headers->get(self::HEADER);
    }
}
