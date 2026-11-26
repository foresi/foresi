<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Screen;

use Foresi\Meetings\Application\MeetingReads;
use Foresi\Meetings\Application\MeetingTargets;
use Foresi\Meetings\Application\MeetingsStore;
use Foresi\Meetings\Domain\Meeting;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * How the screens of Meetings put a meeting into words: its day and time, its
 * series, its attendees and the records it is about. The rows of a list are read
 * once (prime()), so a column never asks for one meeting at a time.
 */
final class MeetingScreen
{
    /** @var array<string, list<array{id: string, name: string}>> */
    private array $attendees = [];

    /** @var array<string, list<string>> the labels of the records seen, by meeting id */
    private array $links = [];

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly MeetingReads $reads,
        private readonly MeetingsStore $store,
        private readonly MeetingTargets $targets,
    ) {
    }

    /**
     * Reads the attendees and the linked records of the rows of a list at once.
     *
     * @param list<Meeting> $meetings
     */
    public function prime(array $meetings): void
    {
        $ids = array_map(static fn (Meeting $meeting): string => $meeting->id(), $meetings);
        $this->attendees = $this->reads->attendees($ids);
        $this->links = [];
        foreach ($this->store->linksOf($ids) as $meetingId => $links) {
            foreach ($links as [$kind, $id]) {
                $view = $this->targets->view($kind, $id);
                if (null !== $view) {
                    $this->links[$meetingId][] = $view['label'];
                }
            }
        }
    }

    /**
     * "1. 12. 2026 09:30", the day alone without a time.
     */
    public function when(Meeting $meeting): string
    {
        $day = $meeting->heldOn()->format($this->translator->trans('format.date'));

        return null === $meeting->startsAt() ? $day : $day.' '.$meeting->startsAt();
    }

    public function series(Meeting $meeting): string
    {
        return $meeting->series()?->name() ?? $this->translator->trans('meetings.meeting.one_off');
    }

    public function attendees(Meeting $meeting): string
    {
        return implode(', ', array_column($this->attendees[$meeting->id()] ?? [], 'name'));
    }

    public function links(Meeting $meeting): string
    {
        return implode(', ', $this->links[$meeting->id()] ?? []);
    }
}
