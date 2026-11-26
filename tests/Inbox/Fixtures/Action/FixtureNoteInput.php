<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Fixtures\Action;

use Foresi\Inbox\Contract\InboxAction;
use Foresi\Inbox\Contract\InboxActionInput;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * The action of a module only the tests have: a note from an e-mail, a record
 * of a kind no ActivityTarget offers. It fails on demand, before the note is
 * made (fail) or after it while Inbox records it (failRecording); or it makes
 * a real contact in the database and then refuses (failAfter) or throws an
 * error that is no refusal (explode), to show what a failing step leaves.
 */
#[Operation(
    name: 'inbox.actions.fixture_note',
    method: 'POST',
    path: '/api/v1/inbox/items/{inboxItemId}/fixture-notes',
    tool: 'foresi_add_fixture_note_from_email',
    scope: 'views:write',
    title: 'Add a note from an e-mail (tests only)',
    description: 'Adds a note made from one of your inbox items; a module of the tests only.',
    status: 201,
    staffOnly: true,
)]
#[InboxAction(label: 'inbox_fixture.action.note', kind: FixtureNotes::KIND, weight: 50)]
final readonly class FixtureNoteInput implements InboxActionInput
{
    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $inboxItemId,
        #[Field('The text of the note.', maxLength: 200)]
        public string $text,
        #[Field('What the note is about.', enum: ['mail', 'call'])]
        public string $noteKind = 'mail',
        #[Field('Fail instead of making the note.')]
        public bool $fail = false,
        #[Field('Mark the item done after the note is made.')]
        public bool $markDone = true,
        #[Field('Make the note, then fail while Inbox adds it to the item.')]
        public bool $failRecording = false,
        #[Field('A record the note is about, such as the task an earlier step of a suggestion made.', format: 'uuid')]
        public ?string $aboutId = null,
        #[Field('Make a contact, then refuse as fail does.')]
        public bool $failAfter = false,
        #[Field('Make a contact, then fail with an error that is no refusal.')]
        public bool $explode = false,
    ) {
    }

    public function inboxItemId(): string
    {
        return $this->inboxItemId;
    }

    public function markDone(): bool
    {
        return $this->markDone;
    }

    /**
     * @throws \RuntimeException when the test asks the recording to fail, after the output was added
     */
    public function relatedRecords(): array
    {
        if ($this->failRecording) {
            throw new \RuntimeException('The recording failed, as the test asked.');
        }

        return [];
    }
}
