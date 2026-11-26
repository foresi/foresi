<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * What an agent proposes to do with an inbox item: one or more steps, each an
 * inbox action with its input (SuggestionStep), with the agent's rationale and
 * the quote of the mail it rests on. Nothing of it runs until the item's owner
 * accepts it; they may untick or edit steps first, or reject it. The agent may
 * replace its own open suggestion with a new one (superseded).
 *
 * Audited as its own record, its steps under it, so the trail shows who
 * proposed what, who decided, and the input each step ran with next to the
 * proposed one. The rationale and the quote are the agent's text, the quote
 * the mail's: data, never instructions.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inbox_suggestion')]
#[ORM\Index(name: 'idx_inbox_suggestion_item_state', columns: ['item_id', 'state'])]
class Suggestion implements Audited
{
    public const int MAX_STEPS = 10;
    public const int MAX_RATIONALE = 2000;
    public const int MAX_QUOTE = 1000;
    public const int MAX_CLIENT = 100;
    public const int MAX_REJECT_REASON = 500;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 16, enumType: SuggestionState::class)]
    private SuggestionState $state = SuggestionState::Proposed;

    /** The client name of the token that proposed it, such as the agent's, cut to 100 characters. */
    #[ORM\Column(name: 'proposed_by_client', type: 'string', length: self::MAX_CLIENT)]
    private string $proposedByClient;

    #[ORM\Column(name: 'proposed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $proposedAt;

    /** The suggestion of the same agent that replaced this one. */
    #[ORM\Column(name: 'superseded_by', type: 'guid', nullable: true)]
    private ?string $supersededBy = null;

    /** Who accepted or rejected it; nobody for one superseded. */
    #[ORM\Column(name: 'decided_by', type: 'guid', nullable: true)]
    private ?string $decidedBy = null;

    /** When it was accepted, rejected or superseded. */
    #[ORM\Column(name: 'decided_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(name: 'reject_reason', type: 'string', length: self::MAX_REJECT_REASON, nullable: true)]
    private ?string $rejectReason = null;

    /** @var Collection<int, SuggestionStep> */
    #[ORM\OneToMany(targetEntity: SuggestionStep::class, mappedBy: 'suggestion', cascade: ['persist', 'remove', 'refresh'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $steps;

    /**
     * @param list<array{operation: string, input: array<mixed>}> $steps       in their order, positions 1, 2, …
     * @param int                                                 $quoteOffset where the quote starts in the item's text, in characters
     */
    public function __construct(
        string $id,
        #[ORM\ManyToOne(targetEntity: InboxItem::class)]
        #[ORM\JoinColumn(name: 'item_id', nullable: false, onDelete: 'CASCADE')]
        private InboxItem $item,
        array $steps,
        #[ORM\Column(type: 'text')]
        private string $rationale,
        #[ORM\Column(type: 'text')]
        private string $quote,
        #[ORM\Column(name: 'quote_offset', type: 'integer')]
        private int $quoteOffset,
        #[ORM\Column(name: 'proposed_by_user_id', type: 'guid')]
        private string $proposedByUserId,
        #[ORM\Column(name: 'proposed_by_token_id', type: 'guid')]
        private string $proposedByTokenId,
        string $clientName,
        \DateTimeImmutable $now,
    ) {
        if (!Id::isValid($id) || !Id::isValid($proposedByUserId) || !Id::isValid($proposedByTokenId)) {
            throw new \InvalidArgumentException('A suggestion, its proposer and its token have UUIDv7 ids.');
        }
        if ('' === trim($rationale) || mb_strlen($rationale) > self::MAX_RATIONALE) {
            throw new \InvalidArgumentException('A rationale has 1 to 2,000 characters.');
        }
        if ('' === $quote || mb_strlen($quote) > self::MAX_QUOTE) {
            throw new \InvalidArgumentException('A quote has 1 to 1,000 characters.');
        }
        if ($quoteOffset < 0) {
            throw new \InvalidArgumentException('A quote starts at an offset of 0 or more.');
        }
        if ([] === $steps || \count($steps) > self::MAX_STEPS) {
            throw new \InvalidArgumentException('A suggestion has 1 to 10 steps.');
        }
        $clientName = mb_substr(trim($clientName), 0, self::MAX_CLIENT);
        if ('' === $clientName) {
            throw new \InvalidArgumentException('A suggestion names the client that proposed it.');
        }
        $this->id = $id;
        $this->proposedByClient = $clientName;
        $this->proposedAt = $this->utc($now);
        $this->steps = new ArrayCollection();
        foreach ($steps as $index => $step) {
            $this->steps->add(new SuggestionStep(Id::generate(), $this, $index + 1, $step['operation'], $step['input']));
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function item(): InboxItem
    {
        return $this->item;
    }

    public function itemId(): string
    {
        return $this->item->id();
    }

    public function state(): SuggestionState
    {
        return $this->state;
    }

    public function isOpen(): bool
    {
        return SuggestionState::Proposed === $this->state;
    }

    public function rationale(): string
    {
        return $this->rationale;
    }

    public function quote(): string
    {
        return $this->quote;
    }

    public function quoteOffset(): int
    {
        return $this->quoteOffset;
    }

    public function proposedByUserId(): string
    {
        return $this->proposedByUserId;
    }

    public function proposedByTokenId(): string
    {
        return $this->proposedByTokenId;
    }

    public function isProposedByToken(?string $tokenId): bool
    {
        return null !== $tokenId && $tokenId === $this->proposedByTokenId;
    }

    public function proposedByClient(): string
    {
        return $this->proposedByClient;
    }

    public function proposedAt(): \DateTimeImmutable
    {
        return $this->proposedAt;
    }

    public function supersededBy(): ?string
    {
        return $this->supersededBy;
    }

    public function decidedBy(): ?string
    {
        return $this->decidedBy;
    }

    public function decidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function rejectReason(): ?string
    {
        return $this->rejectReason;
    }

    /**
     * @return list<SuggestionStep> by position
     */
    public function steps(): array
    {
        // In order already: built in order, and read back by the position (OrderBy).
        return $this->steps->getValues();
    }

    /**
     * @throws \OutOfRangeException for a position the suggestion has no step at
     */
    public function step(int $position): SuggestionStep
    {
        foreach ($this->steps as $step) {
            if ($step->position() === $position) {
                return $step;
            }
        }

        throw new \OutOfRangeException(\sprintf('The suggestion has no step %d.', $position));
    }

    /**
     * The steps that run when it is accepted, in their order: ticked, and pending or failed.
     *
     * @return list<SuggestionStep>
     */
    public function stepsToRun(): array
    {
        return array_values(array_filter($this->steps(), static fn (SuggestionStep $step): bool => $step->isRunnable()));
    }

    /**
     * The record ids of the done steps, by position, for the references of a later step (StepReferences).
     *
     * @return array<int, string>
     */
    public function doneRecords(): array
    {
        $records = [];
        foreach ($this->steps() as $step) {
            // Only a done step holds a record.
            $recordId = $step->recordId();
            if (null !== $recordId) {
                $records[$step->position()] = $recordId;
            }
        }

        return $records;
    }

    /**
     * Replaced by the same agent's new suggestion.
     *
     * @throws \LogicException when it is no longer open
     */
    public function supersede(string $byId, \DateTimeImmutable $now): void
    {
        $this->mustBeOpen();
        if (!Id::isValid($byId) || $byId === $this->id) {
            throw new \InvalidArgumentException('A suggestion is superseded by another one, named by its UUIDv7 id.');
        }
        $this->state = SuggestionState::Superseded;
        $this->supersededBy = $byId;
        $this->decidedAt = $this->utc($now);
    }

    /**
     * @param string|null $reason why, at most 500 characters; blank is none
     *
     * @throws \LogicException when it is no longer open
     */
    public function reject(string $userId, ?string $reason, \DateTimeImmutable $now): void
    {
        $this->mustBeOpen();
        $reason = null === $reason || '' === trim($reason) ? null : trim($reason);
        if (null !== $reason && mb_strlen($reason) > self::MAX_REJECT_REASON) {
            throw new \InvalidArgumentException('A reject reason has at most 500 characters.');
        }
        $this->state = SuggestionState::Rejected;
        $this->decidedBy = $userId;
        $this->decidedAt = $this->utc($now);
        $this->rejectReason = $reason;
    }

    /**
     * Stores the person's edit of a step before it runs; the proposed input stays.
     *
     * @param array<mixed> $input
     *
     * @throws \LogicException when it is no longer open, or the step is done or skipped
     */
    public function editStep(int $position, array $input): void
    {
        $this->mustBeOpen();
        $this->step($position)->edit($input);
    }

    /**
     * The person unticked the step: it is skipped and never runs.
     *
     * @throws \LogicException when it is no longer open, or the step is done
     */
    public function unselectStep(int $position): void
    {
        $this->mustBeOpen();
        $this->step($position)->skip();
    }

    /**
     * Accepted once every ticked step is done and at least one is: the others
     * were unticked (skipped). While a ticked step is pending or failed it
     * stays proposed, so a retry runs the rest.
     *
     * @return bool whether it became accepted now
     */
    public function acceptIfComplete(string $userId, \DateTimeImmutable $now): bool
    {
        if (!$this->isOpen()) {
            return false;
        }
        $steps = $this->steps();
        $done = array_filter($steps, static fn (SuggestionStep $step): bool => StepState::Done === $step->state());
        $complete = array_all($steps, static fn (SuggestionStep $step): bool => \in_array($step->state(), [StepState::Done, StepState::Skipped], true));
        if (!$complete || [] === $done) {
            return false;
        }
        $this->state = SuggestionState::Accepted;
        $this->decidedBy = $userId;
        $this->decidedAt = $this->utc($now);

        return true;
    }

    private function mustBeOpen(): void
    {
        if (!$this->isOpen()) {
            throw new \LogicException(\sprintf('The suggestion is %s already.', $this->state->value));
        }
    }

    private function utc(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->setTimezone(new \DateTimeZone('UTC'));
    }
}
