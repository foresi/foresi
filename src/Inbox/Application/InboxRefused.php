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
