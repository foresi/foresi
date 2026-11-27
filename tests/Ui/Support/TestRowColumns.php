<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Support;

use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;

/**
 * The columns of the test rows: their name, always shown, and their id.
 */
final readonly class TestRowColumns implements DataSource
{
    public const string LIST = 'test.rows';

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }

        return [
            new Attribute('name', 'test.column.name', AttributeType::Text, static fn (TestRow $row): string => $row->name, defaultVisible: true, defaultPosition: 10, cell: static fn (TestRow $row): string => $row->name, locked: true, truncate: true),
            new Attribute('id', 'test.column.id', AttributeType::Text, static fn (TestRow $row): string => $row->id, defaultVisible: true, defaultPosition: 20, cell: static fn (TestRow $row): string => $row->id),
        ];
    }
}
