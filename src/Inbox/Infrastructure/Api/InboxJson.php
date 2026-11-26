<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Api;

use Foresi\Inbox\Application\DeclaredAction;
use Foresi\Inbox\Application\InboxActions;
use Foresi\Inbox\Application\ItemDetail;
use Foresi\Inbox\Application\ItemRow;
use Foresi\Inbox\Application\OutputView;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Contract\MailAttachment;
use Foresi\Mail\Contract\MailMessageView;
use Foresi\Mail\Contract\SenderCheck;
use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Infrastructure\Operation\InputSchema;
use Foresi\Shared\Infrastructure\Operation\OperationField;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An inbox item as REST and MCP answer it, the schemas InboxItem and
 * InboxItemSummary. Times are in the instance's zone. The sender, subject, body
 * and attachment names are the sender's own words; MCP marks them as such.
 *
 * An item lists the actions the modules offer on it that the token may run
 * (available_actions): the operation, its MCP tool, its label in English, the
 * schema of its input and the values it starts with (prefill, with choices for
 * a field picked from a list), all by the fields' wire names; and the
 * suggestions agents proposed on it (SuggestionJson), the oldest first. A row
 * says how many of them are still open.
 */
final readonly class InboxJson
{
    /** The scope of an agent that proposes suggestions: it sees every action of the item, runnable or not. */
    public const string SUGGEST_SCOPE = 'inbox_suggestions:write';

    public function __construct(
        private InstanceClock $clock,
        private InboxActions $actions,
        private ScopeGuard $scopes,
        private TranslatorInterface $translator,
        private SuggestionJson $suggestions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function item(ItemDetail $detail): array
    {
        $item = $detail->item;
        $message = $detail->message;

        return $this->common($item, $message?->from, $message?->subject, $message->verdict ?? SenderCheck::UNKNOWN) + [
            'warnings' => $detail->warnings(),
            'duplicate_of_purpose' => $item->duplicateOfPurpose(),
            'mail_removed' => !$message instanceof MailMessageView,
            'body' => $detail->text,
            'attachments' => array_map(static fn (MailAttachment $attachment): array => [
                'index' => $attachment->index,
                'name' => $attachment->name,
                'mime' => $attachment->contentType,
                'size' => $attachment->size,
            ], $detail->attachments),
            'thread_item_ids' => array_map(static fn (InboxItem $other): string => $other->id(), $detail->thread),
            'outputs' => array_map(fn (OutputView $output): array => [
                'kind' => $output->output->kind(),
                'id' => $output->output->recordId(),
                'available' => $output->available,
                'label' => $output->label,
                'url' => $output->url,
                'added_at' => $this->time($output->output->addedAt()),
            ], $detail->outputs),
            'email_activity_id' => $item->emailActivityId(),
            'trashed_at' => $item->trashedAt() instanceof \DateTimeImmutable ? $this->time($item->trashedAt()) : null,
            'available_actions' => $this->availableActions($detail),
            'suggestions' => array_map($this->suggestions->of(...), $detail->suggestions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function row(ItemRow $row): array
    {
        return $this->common($row->item, $row->message?->from, $row->message?->subject, $row->message->verdict ?? SenderCheck::UNKNOWN) + [
            'others_in_thread' => $row->otherInThread,
            'output_count' => $row->outputCount,
            'open_suggestions' => $row->openSuggestions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function common(InboxItem $item, ?string $from, ?string $subject, string $verdict): array
    {
        return [
            'id' => $item->id(),
            'state' => $item->state()->value,
            'state_changed_at' => $this->time($item->stateChangedAt()),
            'received_at' => $this->time($item->receivedAt()),
            'from' => $from,
            'subject' => $subject,
            'verdict' => $verdict,
            'forwarded' => $item->isForwarded(),
            'original_from' => $item->originalFrom(),
            'contact_id' => $item->contactId(),
            'company_id' => $item->companyId(),
            'match_basis' => $item->matchBasis()?->value,
            'thread_key' => $item->threadKey(),
        ];
    }

    /**
     * The actions on the item the token may run (its scope and inbox:read), each with allowed
     * true; for a token that proposes suggestions every action that applies, allowed saying
     * whether it may also run it. None on an item in the trash.
     *
     * @return list<array<string, mixed>>
     */
    private function availableActions(ItemDetail $detail): array
    {
        if ($detail->item->isTrashed() || !$this->scopes->allows(InboxApiController::READ_SCOPE)) {
            return [];
        }
        $view = $detail->view(true);
        // An agent that proposes (inbox_suggestions:write) learns every action it may propose,
        // with whether its own token may also run it; other tokens see the ones they may run.
        $proposes = $this->scopes->allows(self::SUGGEST_SCOPE);

        return array_map(function (DeclaredAction $action) use ($view): array {
            $operation = $action->definition->operation;
            $prefill = $this->actions->prefill($action, $view);
            $answer = [
                'operation' => $operation->name,
                'tool' => $operation->tool,
                'allowed' => $this->scopes->allows($operation->scope),
                'label' => $this->translator->trans($action->action->label, [], 'messages', 'en'),
                'input_schema' => InputSchema::of($action->definition->fields),
                'prefill' => ['inbox_item_id' => $view->id] + $this->byWireName($prefill->values),
            ];
            if ([] !== $prefill->choices) {
                $answer['choices'] = $this->byWireName($prefill->choices);
            }

            return $answer;
        }, $this->actions->available($view, $proposes ? null : $this->scopes->allows(...)));
    }

    /**
     * @template T
     *
     * @param array<string, T> $values by field name
     *
     * @return array<string, T> by wire name
     */
    private function byWireName(array $values): array
    {
        $wired = [];
        foreach ($values as $name => $value) {
            $wired[OperationField::wireName($name)] = $value;
        }

        return $wired;
    }

    private function time(\DateTimeImmutable $time): string
    {
        return $time->setTimezone($this->clock->timezone())->format(\DATE_ATOM);
    }
}
