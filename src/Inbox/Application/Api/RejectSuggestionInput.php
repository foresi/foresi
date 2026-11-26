<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application\Api;

use Foresi\Inbox\Domain\Suggestion;
use Foresi\Shared\Contract\Operation\Field;
use Foresi\Shared\Contract\Operation\Operation;

#[Operation(
    name: 'inbox.suggestions.reject',
    method: 'POST',
    path: '/api/v1/inbox/suggestions/{suggestionId}/reject',
    tool: 'foresi_reject_inbox_suggestion',
    scope: 'inbox_decisions:write',
    title: 'Reject a suggestion on your inbox item',
    description: 'Rejects an open suggestion on one of your inbox items, with an optional reason: no further step of it runs; steps already done keep what they made. Refused with not_open when it is accepted, rejected or superseded already. '
        .'Rejecting the last open suggestion of a new item marks the item done when another of its suggestions was accepted. Needs inbox:read too.',
    response: 'InboxSuggestionDecision',
    staffOnly: true,
)]
final readonly class RejectSuggestionInput
{
    public function __construct(
        #[Field('The id of the suggestion.', format: 'uuid')]
        public string $suggestionId,
        #[Field('Why, for the trail.', maxLength: Suggestion::MAX_REJECT_REASON)]
        public ?string $reason = null,
    ) {
    }
}
