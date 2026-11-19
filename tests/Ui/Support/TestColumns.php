<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Support;

use Foresi\Ui\Column\ListColumnRegistry;
use Foresi\Ui\Contract\DataSource;
use Foresi\Ui\Format\AttributeCells;
use Foresi\Ui\Format\MoneyText;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * A column registry over the given data sources, its labels and values untranslated.
 */
final class TestColumns
{
    public static function registry(DataSource ...$sources): ListColumnRegistry
    {
        return new ListColumnRegistry($sources, new IdentityTranslator(), self::cells());
    }

    public static function cells(): AttributeCells
    {
        return new AttributeCells(new IdentityTranslator(), new MoneyText(new LocaleSwitcher('en', [])));
    }
}
