<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Inbox\Domain\ItemState;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Shared\Contract\Refused;

/**
 * What the inbox refuses, answered by the shared refusal mapping on the web,
 * REST and MCP. Another person's item is not_found, as one that does not exist.
 * A message never quotes the mail or what the person sent.
 */
final class InboxRefused extends \DomainException implements Refused
{
    /**
     * @param array<string, scalar|list<scalar>|null> $details
     */
    private function __construct(
        private readonly string $reason,
        private readonly RefusalKind $kind,
        private readonly ?string $field,
        string $message,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $details
     */
    public static function invalid(string $reason, string $field, string $message, array $details = []): self
    {
        return new self($reason, RefusalKind::Invalid, $field, $message, $details);
    }

    public static function conflict(string $reason, string $message): self
    {
        return new self($reason, RefusalKind::Conflict, null, $message);
    }

    /**
     * The item is in this state already, as done twice: already_done.
     */
    public static function already(ItemState $state): self
    {
        return self::conflict('already_'.$state->value, \sprintf('The item is %s already.', $state->value));
    }

    public static function notFound(): self
    {
        return new self('not_found', RefusalKind::NotFound, null, 'You have no inbox item with this id. Find yours with foresi_list_inbox_items.');
    }

    public static function forbidden(): self
    {
        return new self('forbidden', RefusalKind::Forbidden, null, 'Only the owners and members of the organisation have an inbox.');
    }

    /**
     * The token lacks a second scope an operation needs, as contacts:write to create a contact.
     */
    public static function insufficientScope(string $scope): self
    {
        return new self('insufficient_scope', RefusalKind::Forbidden, null, \sprintf('The token does not have the "%s" scope.', $scope));
    }

    /**
     * An action on an item in the trash: restore it first.
     */
    public static function itemTrashed(): self
    {
        return self::conflict('item_trashed', 'The inbox item is in the trash; restore it with foresi_restore_inbox_item first.');
    }

    /**
     * An action whose condition the item does not meet, such as one that needs a matched contact.
     */
    public static function actionNotApplicable(): self
    {
        return self::conflict('action_not_applicable', 'This action does not apply to the inbox item; foresi_get_inbox_item lists the ones that do in available_actions.');
    }

    /**
     * An action on an item without its e-mail activity while Activities takes
     * none (the type email is archived or missing): what it made could not be
     * added to the item, so it is not made.
     */
    public static function emailActivityUnavailable(): self
    {
        return self::conflict('email_activity_unavailable', 'The activity type email is archived or missing, so what the action makes cannot be added to the inbox item; restore the type under Settings, Activities first.');
    }

    /**
     * A field of a suggestion, or of a step's input, that does not fit: named by
     * its path, such as suggestions.0.steps.1.input.projectId (input.project_id on the wire).
     */
    public static function invalidInput(string $field, string $message): self
    {
        return self::invalid('invalid_input', $field, $message);
    }

    /**
     * More suggestions in one call, or steps in one suggestion, than allowed.
     */
    public static function tooMany(string $field, int $most): self
    {
        return self::invalid('too_many', $field, \sprintf('At most %d are allowed here.', $most), ['max' => $most]);
    }

    /**
     * A step whose operation is no action the item offers (available_actions).
     */
    public static function stepNotApplicable(string $field): self
    {
        return self::invalid('action_not_applicable', $field, 'This operation is no action of the inbox item; foresi_get_inbox_item lists the ones that apply in available_actions.');
    }

    /**
     * A {"$step": n, "field": "id"} that names no earlier step of the same suggestion.
     */
    public static function invalidReference(string $field): self
    {
        return self::invalid('invalid_reference', $field, 'A reference names an earlier step of the same suggestion by its position, as {"$step": 1, "field": "id"}.');
    }

    /**
     * A reference to a step that made no record, such as one the person unticked.
     */
    public static function referenceUnresolved(string $field): self
    {
        return self::invalid('reference_unresolved', $field, 'The step this field refers to made no record, so this step cannot run.');
    }

    /**
     * A quote that is not an exact span of the mail's text.
     */
    public static function quoteNotInEmail(string $field): self
    {
        return self::invalid('quote_not_in_email', $field, 'The quote must be an exact span of the item\'s body, as foresi_get_inbox_item returns it.');
    }

    /**
     * Suggestions are proposed on new items only.
     */
    public static function itemClosed(): self
    {
        return self::conflict('item_closed', 'The inbox item is done or discarded; suggestions are proposed on new items only.');
    }

    /**
     * A suggestion named by the call that the item does not have, or that is not the person's.
     */
    public static function suggestionNotFound(string $field): self
    {
        return self::invalid('not_found', $field, 'The inbox item has no suggestion with this id.');
    }

    /**
     * A suggestion accepted, rejected or superseded already.
     */
    public static function suggestionNotOpen(string $field): self
    {
        return self::invalid('not_open', $field, 'The suggestion is accepted, rejected or superseded already.');
    }

    /**
     * A suggestion to replace that another token proposed: an agent replaces its own only.
     */
    public static function notYours(string $field): self
    {
        return self::invalid('not_yours', $field, 'Another client proposed this suggestion; you may replace only your own.');
    }

    /**
     * A suggestion named in the path that does not exist or is on another person's item.
     */
    public static function noSuggestion(): self
    {
        return new self('not_found', RefusalKind::NotFound, null, 'You have no inbox suggestion with this id; foresi_get_inbox_item lists the suggestions of an item.');
    }

    /**
     * A suggestion named in the path that is accepted, rejected or superseded already.
     */
    public static function suggestionClosed(): self
    {
        return self::conflict('not_open', 'The suggestion is accepted, rejected or superseded already.');
    }

    /**
     * Marking an item done while suggestions on it wait for a decision: say what becomes of them.
     */
    public static function openSuggestions(int $open): self
    {
        return new self('open_suggestions', RefusalKind::Conflict, null, 'Suggestions on the inbox item wait for a decision; send suggestions "reject" to reject them or "keep" to keep them on the done item.', ['open' => $open]);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function kind(): RefusalKind
    {
        return $this->kind;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function details(): array
    {
        return $this->details;
    }
}
