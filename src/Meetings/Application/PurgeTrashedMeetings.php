<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Meetings\Contract\Field\MeetingExtension;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\Fields;

/**
 * Removes for good the meetings that have been in the trash for more than 30
 * days, each with its attendees, links and custom fields, as Work purges its
 * tasks. The audit log keeps what they were. Each meeting goes in a unit of work
 * of its own, so one that fails stays for the next run.
 */
final readonly class PurgeTrashedMeetings
{
    public const int DAYS = 30;

    public function __construct(private MeetingsStore $store, private MeetingsWork $work, private Fields $fields)
    {
    }

    /**
     * @return int how many meetings were removed
     */
    public function run(\DateTimeImmutable $now): int
    {
        $removed = 0;
        $keys = array_map(static fn (FieldDefinition $definition): string => $definition->key, $this->fields->definitions(MeetingExtension::RECORD));
        foreach ($this->store->trashedBefore($now->modify(\sprintf('-%d days', self::DAYS))) as $meeting) {
            $this->work->run(function () use ($meeting, $keys): void {
                foreach ([...$this->store->attendees($meeting), ...$this->store->links($meeting)] as $row) {
                    $this->store->remove($row);
                }
                if ([] !== $keys) {
                    $this->fields->write(MeetingExtension::RECORD, $meeting->id(), array_fill_keys($keys, null));
                }
                $this->store->remove($meeting);
            });
            ++$removed;
        }

        return $removed;
    }
}
