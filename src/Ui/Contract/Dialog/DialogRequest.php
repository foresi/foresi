<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract\Dialog;

use Symfony\Component\HttpFoundation\Request;

/**
 * A request the dialog controller sent: it wants the page's dialog alone, not
 * the whole page. The same URL without the header is the full page, which is
 * what a link opens without JavaScript, in a new tab or from a bookmark.
 *
 * The header names the layer that asks: "sheet" for the side sheet, "1" for a
 * modal dialog (docs/design/overlays.md).
 */
final class DialogRequest
{
    public const string HEADER = 'X-Foresi-Dialog';
    public const string SHEET = 'sheet';
    public const string DIALOG = 'dialog';

    public static function is(Request $request): bool
    {
        return null !== self::layer($request);
    }

    /**
     * The layer that asks for the page: self::SHEET, self::DIALOG or null for the page itself.
     */
    public static function layer(Request $request): ?string
    {
        return match ($request->headers->get(self::HEADER)) {
            '1' => self::DIALOG,
            self::SHEET => self::SHEET,
            default => null,
        };
    }
}
