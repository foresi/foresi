<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Audit\Application\AuditActor;
use Foresi\Meetings\Application\Api\CreateActionItemInput;
use Foresi\Meetings\Contract\Event\ActionItemCreated;
use Foresi\Meetings\Contract\Event\MeetingMinutesChanged;
use Foresi\Meetings\Domain\ActionItem;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MinutesToken;
use Foresi\Shared\Contract\Event\EventRecorder;
use Foresi\Shared\Contract\Refused;
use Foresi\Shared\Domain\Id;
use Foresi\Work\Contract\TaskIntake;

/**
 * The action items of a meeting (spec, Create task from the minutes): a task
 * made in Work through TaskIntake, with Work's own checks and event, and its
 * link to the meeting, in one unit of work with meetings.action_item.created.
 * Whoever sees a meeting not in the trash makes them. From the editor, the
 * editor puts the token in the minutes and saves them as any change; over the
 * API and MCP the token goes into the minutes in the same unit of work.
 */
final readonly class ActionItemWrites
{
    public function __construct(
        private MeetingVisibility $visibility,
        private MeetingsStore $store,
        private MeetingsWork $work,
        private TaskIntake $intake,
        private EventRecorder $events,
        private MeetingsClock $clock,
        private AuditActor $actor,
    ) {
    }

    /**
     * From the editor's dialog. The key is the id the dialog chose for the item:
     * a second request with it (a double click, a retry) answers the item the
     * first one made, and makes no second task.
     *
     * @throws MeetingsRefused
     * @throws Refused         with Work's reasons
     */
    public function fromEditor(string $meetingId, string $key, string $title, string $projectId, ?string $responsibleId, ?\DateTimeImmutable $dueOn, bool $private): ActionItem
    {
        return $this->work->run(function () use ($meetingId, $key, $title, $projectId, $responsibleId, $dueOn, $private): ActionItem {
            $meeting = $this->open($meetingId);
            $key = strtolower($key);
            if (!Id::isValid($key)) {
                throw MeetingsRefused::invalid('key', 'key', 'The key of an action item is a UUID.');
            }
            $made = $this->store->actionItem($key);
            if ($made instanceof ActionItem) {
                if ($made->meeting()->id() !== $meeting->id() || $made->createdBy() !== ($this->actor->userId() ?? '')) {
                    throw MeetingsRefused::invalid('key', 'key', 'The key belongs to another action item.');
                }

                return $made;
            }

            return $this->make($meeting, $key, $title, $projectId, $responsibleId, $dueOn, $private);
        });
    }

    /**
     * Over the API and MCP: the task, its link, and its token in the minutes.
     *
     * @return array{ActionItem, Meeting}
     *
     * @throws MeetingsRefused
     * @throws Refused         with Work's reasons
     */
    public function create(CreateActionItemInput $input): array
    {
        return $this->work->run(function () use ($input): array {
            $meeting = $this->open($input->meetingId);
            $dueOn = null;
            if (null !== $input->dueOn) {
                $dueOn = \DateTimeImmutable::createFromFormat('!Y-m-d', $input->dueOn);
                if (!$dueOn instanceof \DateTimeImmutable || $dueOn->format('Y-m-d') !== $input->dueOn) {
                    throw MeetingsRefused::invalid('due_on', 'due_on', 'The due day is YYYY-MM-DD.');
                }
            }
            $quote = null === $input->quote || '' === $input->quote ? null : $input->quote;
            if (null !== $quote && null === MinutesToken::inPlaceOf($meeting->minutes(), $quote, Id::generate())) {
                throw MeetingsRefused::invalid('quote_not_found', 'quote', 'The quote is not in the minutes on one line outside another task\'s token. Read the minutes again and send the text exactly as written.');
            }
            $item = $this->make($meeting, Id::generate(), $input->title, $input->projectId, $input->responsibleId, $dueOn, $input->private);
            $minutes = null === $quote
                ? MinutesToken::appended($meeting->minutes(), $input->title, $item->taskId())
                : (string) MinutesToken::inPlaceOf($meeting->minutes(), $quote, $item->taskId());
            if (mb_strlen($minutes) > Meeting::MAX_MINUTES) {
                throw MeetingsRefused::invalid('minutes_too_long', 'minutes', \sprintf('The minutes have at most %d characters.', Meeting::MAX_MINUTES));
            }
            $meeting->writeMinutes($minutes, $meeting->version());
            $this->events->record(new MeetingMinutesChanged($meeting->id(), $meeting->series()?->id(), $meeting->version()));

            return [$item, $meeting];
        });
    }

    /**
     * @throws Refused with Work's reasons
     */
    private function make(Meeting $meeting, string $id, string $title, string $projectId, ?string $responsibleId, ?\DateTimeImmutable $dueOn, bool $private): ActionItem
    {
        $taskId = $this->intake->create($projectId, $title, $responsibleId, $dueOn, $private);
        $item = new ActionItem($id, $meeting, $taskId, $this->actor->userId() ?? '', $this->clock->now());
        $this->store->add($item);
        $this->events->record(new ActionItemCreated($meeting->id(), $meeting->series()?->id(), $taskId));

        return $item;
    }

    /**
     * @throws MeetingsRefused
     */
    private function open(string $id): Meeting
    {
        $this->visibility->denyUnlessStaff();
        $meeting = Id::isValid($id) ? $this->store->meeting($id) : null;
        if (!$meeting instanceof Meeting || !$this->visibility->sees($meeting)) {
            throw MeetingsRefused::meetingNotFound();
        }
        if ($meeting->isTrashed()) {
            throw MeetingsRefused::trashed();
        }

        return $meeting;
    }
}
