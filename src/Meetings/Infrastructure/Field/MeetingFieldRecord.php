<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Field;

use Foresi\Meetings\Application\View\MeetingLists;
use Foresi\Meetings\Contract\Field\MeetingExtension;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Shared\Application\Field\FieldRecord;

/**
 * Meetings take custom fields (ADR 0045) through MeetingExtension: a record
 * kind of a module other than the core's five, declared the way they declare
 * theirs.
 */
final readonly class MeetingFieldRecord implements FieldRecord
{
    public function record(): string
    {
        return MeetingExtension::RECORD;
    }

    public function scope(): string
    {
        return 'meetings:read';
    }

    public function entity(): string
    {
        return Meeting::class;
    }

    public function extension(): string
    {
        return MeetingExtension::class;
    }

    public function lists(): array
    {
        return [MeetingLists::KEY => 'm'];
    }

    public function sorted(): array
    {
        return [];
    }

    public function id(mixed $row): string
    {
        \assert($row instanceof Meeting);

        return $row->id();
    }
}
