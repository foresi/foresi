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
 * of a kind no ActivityTarget offers. It fails on demand.
 */
#[Operation(
    name: 'inbox.actions.fixture_note',
    method: 'POST',
    path: '/api/v1/inbox/items/{inboxItemId}/fixture-notes',
    tool: 'foresi_add_fixture_note_from_email',
    scope: 'activities:write',
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

    public function relatedRecords(): array
    {
        return [];
    }
}
