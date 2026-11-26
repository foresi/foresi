<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Column;

use Foresi\Meetings\Application\View\MeetingLists;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Infrastructure\Screen\MeetingScreen;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;

/**
 * The columns of the meetings (spec, UI): the day held, the title, the series,
 * the attendees and the linked records; the custom fields of a meeting are added
 * by the core.
 */
final readonly class MeetingColumns implements DataSource
{
    public const string LIST = MeetingLists::KEY;

    public function __construct(private MeetingScreen $screen)
    {
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'meetings.meetings.column.'.$key;

        return [
            new Attribute('held_on', $label('held_on'), AttributeType::Date, static fn (Meeting $meeting): \DateTimeImmutable => $meeting->heldOn(), defaultVisible: true, defaultPosition: 10, cell: $this->screen->when(...), locked: true),
            new Attribute('title', $label('title'), AttributeType::Text, static fn (Meeting $meeting): string => $meeting->title(), defaultVisible: true, defaultPosition: 20, cell: static fn (Meeting $meeting): string => $meeting->title(), locked: true, truncate: true, filterable: true, filter: 'm.title'),
            new Attribute('series', $label('series'), AttributeType::Text, $this->screen->series(...), defaultVisible: true, defaultPosition: 30, cell: $this->screen->series(...), phone: false),
            new Attribute('attendees', $label('attendees'), AttributeType::Text, $this->screen->attendees(...), defaultVisible: true, defaultPosition: 40, cell: $this->screen->attendees(...), phone: false, wide: true, truncate: true),
            new Attribute('links', $label('links'), AttributeType::Text, $this->screen->links(...), defaultVisible: true, defaultPosition: 50, cell: $this->screen->links(...), phone: false, truncate: true),
        ];
    }
}
