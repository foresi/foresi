<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Controller;

use Foresi\Inbox\Application\DeclaredAction;
use Foresi\Inbox\Application\InboxActions;
use Foresi\Inbox\Application\ItemDetail;
use Foresi\Inbox\Application\OutputView;
use Foresi\Inbox\Application\StepReferences;
use Foresi\Inbox\Contract\PreFill;
use Foresi\Inbox\Domain\StepState;
use Foresi\Inbox\Domain\Suggestion;
use Foresi\Inbox\Domain\SuggestionState;
use Foresi\Inbox\Domain\SuggestionStep;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Infrastructure\Operation\OperationField;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The suggestions of an item as its page shows them, one card each (HubSpot's
 * approve, reject and edit with the source of a suggestion; Zoho Zia's form
 * filled to save or edit): the open ones first, the oldest first, then the
 * decided ones, compact, with their state and when.
 *
 * A card names its steps by their action's label and their values as "field:
 * value", the source of the suggestion ("e-mail from …, received …") with the
 * quote marked in an excerpt of the mail as the page shows it, and who proposed
 * it. Everything of a card but its labels is an agent's or the sender's text:
 * the parts are plain strings for the template to print escaped, never markup.
 */
final readonly class SuggestionCards
{
    /** How much of the mail an excerpt shows on either side of the quote. */
    private const int CONTEXT = 80;
    /** A value longer than this is cut on the card; the dialog shows it whole. */
    private const int MAX_VALUE = 300;

    public function __construct(
        private InboxActions $actions,
        private ActionForm $form,
        private TranslatorInterface $translator,
        private InstanceClock $clock,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param string|null $shown the mail's text as the page shows it (UntrustedText::shown)
     *
     * @return array{open: list<array<string, mixed>>, decided: list<array<string, mixed>>}
     */
    public function of(ItemDetail $detail, ?string $shown): array
    {
        $open = [];
        $decided = [];
        $trashed = $detail->item->isTrashed();
        $source = $this->translator->trans('inbox.suggestion.source', [
            'sender' => $detail->item->originalFrom() ?? $detail->message->from ?? '',
            'received' => $this->time($detail->item->receivedAt()),
        ]);
        // The pre-fill of each action, once per page: it may look up choices such as the projects.
        /** @var \ArrayObject<string, PreFill> $prefills */
        $prefills = new \ArrayObject();
        foreach ($detail->suggestions as $suggestion) {
            $card = $this->card($detail, $suggestion, $shown, $source, !$trashed && $suggestion->isOpen(), $prefills);
            if ($suggestion->isOpen()) {
                $open[] = $card;
            } else {
                $decided[] = $card;
            }
        }

        return ['open' => $open, 'decided' => $decided];
    }

    /**
     * The quote in its excerpt of the shown text: what comes before it, the quote (marked), what comes
     * after. The quote is an exact span of the stored text at its offset, but the page shows a link
     * with its domain after it, so offsets differ: the span is found as the same occurrence of the
     * quote in the shown text as it is in the stored text (the third "Monday" stays the third). When
     * the shown text has no such occurrence (the quote spans a link), nothing is marked and the quote
     * stands alone ($found false).
     *
     * @param string|null $shown  the text as the page shows it (UntrustedText::shown)
     * @param string|null $stored the text as stored, which the quote's offset counts in
     * @param int         $offset the quote's offset in characters, in the stored text with CRLF as LF
     *
     * @return array{before: string, quote: string, after: string, found: bool}
     */
    public static function excerpt(?string $shown, ?string $stored, string $quote, int $offset): array
    {
        $quote = str_replace("\r\n", "\n", $quote);
        $alone = ['before' => '', 'quote' => $quote, 'after' => '', 'found' => false];
        if (null === $shown || null === $stored || '' === $quote) {
            return $alone;
        }
        // How many occurrences of the quote come before the quoted one in the stored text.
        $nth = \count(array_filter(self::occurrences(str_replace("\r\n", "\n", $stored), $quote), static fn (int $at): bool => $at < $offset));
        $at = self::occurrences($shown, $quote)[$nth] ?? null;
        if (null === $at) {
            return $alone;
        }
        $start = max(0, $at - self::CONTEXT);
        $head = mb_substr($shown, $start, $at - $start);
        $end = $at + mb_strlen($quote);
        $tail = mb_substr($shown, $end, self::CONTEXT);
        if ($start > 0) {
            // From the next word on, so the excerpt does not start inside one.
            $space = mb_strpos($head, ' ');
            $head = '…'.(false === $space ? $head : mb_substr($head, $space + 1));
        }
        if ($end + self::CONTEXT < mb_strlen($shown)) {
            $space = mb_strrpos($tail, ' ');
            $tail = (false === $space ? $tail : mb_substr($tail, 0, $space)).'…';
        }

        return ['before' => $head, 'quote' => $quote, 'after' => $tail, 'found' => true];
    }

    /**
     * Where the quote starts in a text, every occurrence, overlapping ones too, in characters.
     *
     * @return list<int>
     */
    private static function occurrences(string $text, string $quote): array
    {
        $found = [];
        $from = 0;
        while (false !== ($at = mb_strpos($text, $quote, $from))) {
            $found[] = $at;
            $from = $at + 1;
        }

        return $found;
    }

    /**
     * @param \ArrayObject<string, PreFill> $prefills
     *
     * @return array<string, mixed>
     */
    private function card(ItemDetail $detail, Suggestion $suggestion, ?string $shown, string $source, bool $decidable, \ArrayObject $prefills): array
    {
        $steps = [];
        $labels = [];
        foreach ($suggestion->steps() as $step) {
            $steps[] = $shownStep = $this->step($detail, $suggestion, $step, $decidable, $prefills);
            $labels[] = $shownStep['label'];
        }
        $failed = array_any($suggestion->steps(), static fn (SuggestionStep $step): bool => StepState::Failed === $step->state() && $step->isSelected());

        return [
            'id' => $suggestion->id(),
            'state' => $suggestion->state()->value,
            'open' => $suggestion->isOpen(),
            'decidable' => $decidable,
            'title' => implode(' + ', $labels),
            'rationale' => $suggestion->rationale(),
            'source' => $source,
            'excerpt' => self::excerpt($shown, $detail->text, $suggestion->quote(), $suggestion->quoteOffset()),
            'proposed' => $this->translator->trans('inbox.suggestion.proposed_by', ['client' => $suggestion->proposedByClient(), 'time' => $this->time($suggestion->proposedAt())]),
            'decided' => $this->decided($suggestion),
            'rejectReason' => $suggestion->rejectReason(),
            'failed' => $failed,
            'steps' => $steps,
            'rejectHref' => $this->urls->generate(SuggestionController::REJECT_ROUTE, ['id' => $detail->item->id(), 'suggestionId' => $suggestion->id()]),
        ];
    }

    /**
     * @param \ArrayObject<string, PreFill> $prefills
     *
     * @return array<string, mixed>&array{label: string}
     */
    private function step(ItemDetail $detail, Suggestion $suggestion, SuggestionStep $step, bool $decidable, \ArrayObject $prefills): array
    {
        $action = $this->actions->get($step->operation());
        $state = $step->state();
        $refusal = $step->refusal();
        $tickable = $decidable && $step->isRunnable();

        return [
            'position' => $step->position(),
            'label' => $action instanceof DeclaredAction ? $this->translator->trans($action->action->label) : $step->operation(),
            'values' => $this->values($action, $this->prefill($detail, $action, $prefills), $step->input()),
            'state' => $state->value,
            'tickable' => $tickable,
            'refusal' => null === $refusal ? null : $this->refusal($refusal['reason']),
            'record' => $this->record($detail, $step),
            'editHref' => $tickable && $action instanceof DeclaredAction ? $this->urls->generate(SuggestionController::EDIT_ROUTE, ['id' => $detail->item->id(), 'suggestionId' => $suggestion->id(), 'position' => $step->position()]) : null,
        ];
    }

    /**
     * @param \ArrayObject<string, PreFill> $prefills
     */
    private function prefill(ItemDetail $detail, ?DeclaredAction $action, \ArrayObject $prefills): PreFill
    {
        if (!$action instanceof DeclaredAction) {
            return new PreFill();
        }
        $prefill = $prefills[$action->name()] ?? null;
        if (!$prefill instanceof PreFill) {
            $prefill = $this->actions->prefill($action, $detail->view(true));
            $prefills[$action->name()] = $prefill;
        }

        return $prefill;
    }

    /**
     * The values of a step as "field: value", in the order the input gives them, empty ones left out.
     *
     * @param array<mixed> $input
     *
     * @return list<array{label: string, value: string}>
     */
    private function values(?DeclaredAction $action, PreFill $prefill, array $input): array
    {
        $values = [];
        foreach ($input as $wire => $value) {
            $wire = (string) $wire;
            if (null === $value || '' === $value || [] === $value) {
                continue;
            }
            $field = $action instanceof DeclaredAction ? $this->form->field($action, $wire) : null;
            $choices = $field instanceof OperationField ? $prefill->choices[$field->name] ?? [] : [];
            $values[] = [
                'label' => $action instanceof DeclaredAction ? $this->form->labelOf($action, $wire) : $wire,
                'value' => $this->value($value, $choices),
            ];
        }

        return $values;
    }

    /**
     * @param array<int|string, string> $choices the labels of the values the dialog offers
     */
    private function value(mixed $value, array $choices): string
    {
        if (StepReferences::isReference($value) && \is_array($value) && \is_int($value[StepReferences::STEP] ?? null)) {
            return $this->translator->trans('inbox.suggestion.reference', ['step' => $value[StepReferences::STEP]]);
        }
        // A value of a field picked from a list that the list does not hold, such as another
        // client's project: marked, so the person sees the step would act on something not offered.
        if ([] !== $choices && (\is_string($value) || \is_int($value)) && !\array_key_exists($value, $choices)) {
            return $this->translator->trans('inbox.suggestion.not_offered', ['value' => mb_substr((string) $value, 0, self::MAX_VALUE)]);
        }
        $text = match (true) {
            \is_bool($value) => $this->translator->trans($value ? 'inbox.action_dialog.yes' : 'inbox.action_dialog.no'),
            \is_string($value), \is_int($value) => $choices[$value] ?? (string) $value,
            \is_float($value) => (string) $value,
            default => (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR),
        };

        return mb_strlen($text) > self::MAX_VALUE ? mb_substr($text, 0, self::MAX_VALUE).'…' : $text;
    }

    /**
     * What a done step made: the record among what came of the item, with its name and page when it
     * has one, else its kind.
     *
     * @return array{kind: string, label: ?string, url: ?string}|null
     */
    private function record(ItemDetail $detail, SuggestionStep $step): ?array
    {
        $kind = $step->recordKind();
        $id = $step->recordId();
        if (null === $kind || null === $id) {
            return null;
        }
        $shown = array_find($detail->outputs, static fn (OutputView $output): bool => $output->output->kind() === $kind && $output->output->recordId() === $id);

        return [
            'kind' => $this->kindLabel($kind),
            'label' => $shown instanceof OutputView && $shown->available ? $shown->label : null,
            'url' => $shown instanceof OutputView && $shown->available ? $shown->url : null,
        ];
    }

    private function decided(Suggestion $suggestion): ?string
    {
        $at = $suggestion->decidedAt();
        if (!$at instanceof \DateTimeImmutable) {
            return null;
        }

        return match ($suggestion->state()) {
            SuggestionState::Accepted => $this->translator->trans('inbox.suggestion.decided.accepted', ['time' => $this->time($at)]),
            SuggestionState::Rejected => $this->translator->trans('inbox.suggestion.decided.rejected', ['time' => $this->time($at)]),
            SuggestionState::Superseded => $this->translator->trans('inbox.suggestion.decided.superseded', ['time' => $this->time($at)]),
            SuggestionState::Proposed => null,
        };
    }

    /**
     * Why a step failed: Inbox's words for its reason, else the reason's code.
     */
    private function refusal(string $reason): string
    {
        $key = 'inbox.suggestion.refused.'.$reason;
        $text = $this->translator->trans($key);

        return $text === $key ? $reason : $text;
    }

    private function kindLabel(string $kind): string
    {
        $key = 'inbox.output_kind.'.$kind;
        $label = $this->translator->trans($key);

        return $label === $key ? ucfirst(str_replace('_', ' ', $kind)) : $label;
    }

    private function time(\DateTimeImmutable $time): string
    {
        return $time->setTimezone($this->clock->timezone())->format($this->translator->trans('format.datetime'));
    }
}
