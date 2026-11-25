<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Fixtures\Field;

use Foresi\Shared\Application\Field\FieldRecord;

/**
 * The test-only record kind, registered in the test environment only: its list
 * "fixture.notes" reads FixtureNote rows under the alias "n" and sorts by columns.
 */
final readonly class FixtureNoteRecord implements FieldRecord
{
    public const string LIST = 'fixture.notes';

    public function record(): string
    {
        return FixtureNoteExtension::RECORD;
    }

    public function entity(): string
    {
        return FixtureNote::class;
    }

    public function extension(): string
    {
        return FixtureNoteExtension::class;
    }

    public function lists(): array
    {
        return [self::LIST => 'n'];
    }

    public function sorted(): array
    {
        return [self::LIST];
    }

    public function id(mixed $row): string
    {
        \assert($row instanceof FixtureNote);

        return $row->id();
    }
}
