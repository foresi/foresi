<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Fixtures\Action;

use Foresi\Inbox\Contract\InboxAction;
use Foresi\Inbox\Contract\InboxActionInput;
use Foresi\Inbox\Contract\SuggestedRecord;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

/**
 * The second action of the tests' module: a note on the matched contact, only
 * for an item matched to one (HasMatchedContact). The e-mail activity is
 * linked to the contact as a related record.
 */
#[Operation(
    name: 'inbox.actions.fixture_contact_note',
    method: 'POST',
    path: '/api/v1/inbox/items/{inboxItemId}/fixture-contact-notes',
    tool: 'foresi_add_fixture_contact_note_from_email',
    scope: 'activities:write',
    title: 'Add a contact note from an e-mail (tests only)',
    description: 'Adds a note on the matched contact of one of your inbox items; a module of the tests only.',
    status: 201,
    staffOnly: true,
)]
#[InboxAction(label: 'inbox_fixture.action.contact_note', kind: FixtureNotes::KIND, appliesTo: HasMatchedContact::class, weight: 60)]
final readonly class FixtureContactNoteInput implements InboxActionInput
{
    public function __construct(
        #[Field('The id of the inbox item.', format: 'uuid')]
        public string $inboxItemId,
        #[Field('The text of the note.', maxLength: 200)]
        public string $text,
        #[Field('The contact the e-mail activity is linked to besides the note.', format: 'uuid')]
        public ?string $contactId = null,
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
        return null === $this->contactId ? [] : [new SuggestedRecord('contact', $this->contactId)];
    }
}
