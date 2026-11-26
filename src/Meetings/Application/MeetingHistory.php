<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Audit\Application\AuditTrail;
use Foresi\Audit\Domain\AuditAction;
use Foresi\Audit\Domain\AuditEntry;
use Foresi\Identity\Application\UserRepository;
use Foresi\Meetings\Domain\Attendee;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingLink;
use Foresi\Shared\Application\Field\FieldProperties;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Work\Application\AuditSteps;

/**
 * The audit log of a meeting as steps a person recognises, as the history of a
 * task: one action is one step. The title, the day, the time and the minutes are
 * told by their label, a custom field by the label of its definition, the
 * attendees by name, and the linked records by how many, since a person may not
 * see a record linked. Going to the trash and back is told as such.
 */
final readonly class MeetingHistory
{
    /** The fields of a meeting, by the last part of their translation key. */
    private const array FIELDS = ['title' => 'title', 'heldOn' => 'held_on', 'startsAt' => 'starts_at', 'minutes' => 'minutes'];

    public function __construct(
        private MeetingReads $reads,
        private AuditTrail $trail,
        private UserRepository $users,
        private MeetingPeople $people,
        private FieldProperties $fieldProperties,
    ) {
    }

    /**
     * @return list<MeetingHistoryEntry> newest first
     *
     * @throws MeetingsRefused when the meeting is unknown or not seen
     */
    public function of(string $meetingId): array
    {
        $meeting = $this->reads->one($meetingId);
        $entries = $this->trail->rootHistory(Meeting::class, $meeting->id());
        $users = $this->users->namesOf(array_values(array_unique(array_filter(array_map(static fn (AuditEntry $entry): ?string => $entry->userId(), $entries), \is_string(...)))));
        $steps = [];
        foreach (AuditSteps::of($entries) as $group) {
            $step = $this->step($group, $users);
            if ($step instanceof MeetingHistoryEntry) {
                $steps[] = $step;
            }
        }

        return array_reverse($steps);
    }

    /**
     * @param non-empty-list<AuditEntry> $group
     * @param array<string, string>      $users names by user id
     */
    private function step(array $group, array $users): ?MeetingHistoryEntry
    {
        $userId = $group[0]->userId();
        $at = $group[0]->occurredAt();
        $name = null === $userId ? null : ($users[$userId] ?? null);
        $fields = [];
        $added = [];
        $removed = [];
        $linked = 0;
        $unlinked = 0;
        foreach ($group as $entry) {
            $changes = $entry->changes();
            $gone = AuditAction::Deleted === $entry->action();
            if (Meeting::class === $entry->entityClass()) {
                if (AuditAction::Created === $entry->action()) {
                    return new MeetingHistoryEntry($at, $name, MeetingHistoryEntry::CREATED);
                }
                if (\array_key_exists('deletedAt', $changes)) {
                    return new MeetingHistoryEntry($at, $name, null === ($changes['deletedAt'][1] ?? null) ? MeetingHistoryEntry::RESTORED : MeetingHistoryEntry::TRASHED);
                }
                foreach (self::FIELDS as $field => $key) {
                    if (\array_key_exists($field, $changes)) {
                        $fields[] = 'meetings.meeting.field.'.$key;
                    }
                }
            } elseif (Attendee::class === $entry->entityClass()) {
                $id = $changes['membershipId'][$gone ? 0 : 1] ?? null;
                if (\is_string($id)) {
                    if ($gone) {
                        $removed[] = $this->people->name($id) ?? '';
                    } else {
                        $added[] = $this->people->name($id) ?? '';
                    }
                }
            } elseif (MeetingLink::class === $entry->entityClass()) {
                if ($gone) {
                    ++$unlinked;
                } elseif (AuditAction::Created === $entry->action()) {
                    ++$linked;
                }
            } else {
                foreach (array_keys($changes) as $property) {
                    $definition = $this->fieldProperties->definitionOf($entry->entityClass(), $property);
                    if ($definition instanceof FieldDefinition) {
                        $fields[] = $definition->label;
                    }
                }
            }
        }
        if ([] === $fields && [] === $added && [] === $removed && 0 === $linked && 0 === $unlinked) {
            return null;
        }

        return new MeetingHistoryEntry($at, $name, MeetingHistoryEntry::CHANGED, array_values(array_unique($fields)), $added, $removed, $linked, $unlinked);
    }
}
