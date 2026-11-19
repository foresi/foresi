<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

/**
 * Where a record a list links to opens (docs/design/overlays.md): in the side
 * sheet, in a modal dialog or as a page of its own. A Ui:DataTable row or cell
 * link names one in "opens".
 */
final class Opens
{
    public const string SHEET = 'sheet';
    public const string DIALOG = 'dialog';
    public const string PAGE = 'page';
    public const array ALL = [self::SHEET, self::DIALOG, self::PAGE];

    private function __construct()
    {
    }
}
