<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Id;

/**
 * Which planned occurrence a received document belongs to, or may belong to.
 * Cash flow decides auto and proposed; a person decides confirmed and declined,
 * and nothing but a person changes those two again. The candidates are kept in
 * every state, so a person can pick another one later.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cash_flow_document_pairing')]
#[ORM\UniqueConstraint(name: 'uniq_cash_flow_document_pairing_subject', columns: ['subject_type', 'subject_id'])]
#[ORM\Index(name: 'idx_cash_flow_document_pairing_occurrence', columns: ['planned_item_id', 'occurrence_on'])]
#[ORM\Index(name: 'idx_cash_flow_document_pairing_counterparty', columns: ['counterparty_id'])]
class DocumentPairing
{
    public const int MAX_SUBJECT_TYPE = 40;
    public const int MAX_SUBJECT_ID = 64;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /** Null when the document is proposed with several candidates, or declined. */
    #[ORM\ManyToOne(targetEntity: PlannedItem::class)]
    #[ORM\JoinColumn(name: 'planned_item_id', nullable: true, onDelete: 'RESTRICT')]
    private ?PlannedItem $plannedItem = null;

    #[ORM\Column(name: 'occurrence_on', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $occurrenceOn = null;

    #[ORM\Column(type: 'string', length: 16, enumType: PairingState::class)]
    private PairingState $state;

    /** @var list<array{planned_item_id: string, occurrence_on: string}> */
    #[ORM\Column(type: 'json')]
    private array $candidates = [];

    #[ORM\Column(name: 'decided_by', type: 'guid', nullable: true)]
    private ?string $decidedBy = null;

    #[ORM\Column(name: 'decided_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        #[ORM\Column(name: 'subject_type', type: 'string', length: self::MAX_SUBJECT_TYPE)]
        private readonly string $subjectType,
        #[ORM\Column(name: 'subject_id', type: 'string', length: self::MAX_SUBJECT_ID)]
        private readonly string $subjectId,
        #[ORM\ManyToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'counterparty_id', nullable: true, onDelete: 'RESTRICT')]
        private ?Party $counterparty,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->state = PairingState::Proposed;
        $this->updatedAt = $createdAt;
    }

    /**
     * A document that belongs to exactly this occurrence, with no one to decide.
     */
    public static function auto(string $subjectType, string $subjectId, ?Party $counterparty, PlannedItem $item, \DateTimeImmutable $on, \DateTimeImmutable $now): self
    {
        $pairing = new self($subjectType, $subjectId, $counterparty, $now);
        $pairing->becomeAuto($item, $on, $counterparty, $now);

        return $pairing;
    }

    /**
     * A document that may belong to one of the candidates, for a person to decide.
     *
     * @param list<PairingCandidate> $candidates at least one
     * @param PlannedItem|null       $only       the item of the one candidate, when there is exactly one
     */
    public static function propose(string $subjectType, string $subjectId, ?Party $counterparty, array $candidates, ?PlannedItem $only, \DateTimeImmutable $now): self
    {
        $pairing = new self($subjectType, $subjectId, $counterparty, $now);
        $pairing->becomeProposed($candidates, $only, $counterparty, $now);

        return $pairing;
    }

    /**
     * Cash flow pairs the document with the occurrence on its own.
     *
     * @throws \LogicException when a person decided already
     */
    public function becomeAuto(PlannedItem $item, \DateTimeImmutable $on, ?Party $counterparty, \DateTimeImmutable $now): void
    {
        $this->denyWhenDecided();
        $this->state = PairingState::Auto;
        $this->point($item, $on);
        $this->candidates = [new PairingCandidate($item->id(), $on)->toArray()];
        $this->counterparty = $counterparty;
        $this->updatedAt = $now;
    }

    /**
     * Cash flow offers the candidates to a person. One candidate is also the occurrence.
     *
     * @param list<PairingCandidate> $candidates at least one
     * @param PlannedItem|null       $only       the item of the one candidate; required with exactly one candidate, ignored otherwise
     *
     * @throws \LogicException when a person decided already, no candidate is given, or $only is not the one candidate
     */
    public function becomeProposed(array $candidates, ?PlannedItem $only, ?Party $counterparty, \DateTimeImmutable $now): void
    {
        $this->denyWhenDecided();
        if ([] === $candidates) {
            throw new \LogicException('A proposal needs a candidate.');
        }
        if (1 === \count($candidates) && (!$only instanceof PlannedItem || $only->id() !== $candidates[0]->plannedItemId)) {
            throw new \LogicException('The item of the one candidate is needed.');
        }
        $this->state = PairingState::Proposed;
        $this->point(1 === \count($candidates) ? $only : null, 1 === \count($candidates) ? $candidates[0]->occurrenceOn : null);
        $this->candidates = array_map(static fn (PairingCandidate $candidate): array => $candidate->toArray(), $candidates);
        $this->counterparty = $counterparty;
        $this->updatedAt = $now;
    }

    /**
     * A person says the document belongs to this occurrence. The candidates stay.
     */
    public function confirm(PlannedItem $item, \DateTimeImmutable $on, string $userId, \DateTimeImmutable $now): void
    {
        $this->state = PairingState::Confirmed;
        $this->point($item, $on);
        $this->decide($userId, $now);
        $this->listCandidate(new PairingCandidate($item->id(), $on));
    }

    /**
     * The chosen occurrence is always among the candidates, also when a person picks one Cash flow did not offer.
     */
    private function listCandidate(PairingCandidate $candidate): void
    {
        $listed = array_any($this->candidates(), static fn (PairingCandidate $stored): bool => $stored->plannedItemId === $candidate->plannedItemId && $stored->occurrenceOn->format('Y-m-d') === $candidate->occurrenceOn->format('Y-m-d'));
        if (!$listed) {
            $this->candidates[] = $candidate->toArray();
        }
    }

    /**
     * A person says the document belongs to none of the candidates. The candidates stay.
     */
    public function decline(string $userId, \DateTimeImmutable $now): void
    {
        $this->state = PairingState::Declined;
        $this->point(null, null);
        $this->decide($userId, $now);
    }

    /**
     * A planned item that is going away leaves the candidates. A proposal that keeps one candidate points at it;
     * a confirmed or declined pairing keeps what the person decided. The caller removes a proposal left with none.
     *
     * @param PlannedItem|null $only the item of the one candidate left, when there is exactly one
     *
     * @throws \LogicException when the pairing points at the item, or a proposal keeps one candidate and $only is not it
     */
    public function dropCandidate(string $plannedItemId, ?PlannedItem $only, \DateTimeImmutable $now): void
    {
        if ($this->plannedItem?->id() === $plannedItemId && PairingState::Proposed !== $this->state) {
            throw new \LogicException('The pairing points at the planned item.');
        }
        $this->candidates = array_values(array_filter($this->candidates, static fn (array $candidate): bool => $candidate['planned_item_id'] !== $plannedItemId));
        $this->updatedAt = $now;
        if (PairingState::Proposed !== $this->state) {
            return;
        }
        $left = $this->candidates();
        if (1 === \count($left) && (!$only instanceof PlannedItem || $only->id() !== $left[0]->plannedItemId)) {
            throw new \LogicException('The item of the one candidate is needed.');
        }
        $this->point(1 === \count($left) ? $only : null, 1 === \count($left) ? $left[0]->occurrenceOn : null);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    public function plannedItemId(): ?string
    {
        return $this->plannedItem?->id();
    }

    public function occurrenceOn(): ?\DateTimeImmutable
    {
        return $this->occurrenceOn;
    }

    public function state(): PairingState
    {
        return $this->state;
    }

    /**
     * @return list<PairingCandidate>
     */
    public function candidates(): array
    {
        return array_map(PairingCandidate::fromArray(...), $this->candidates);
    }

    public function counterpartyId(): ?string
    {
        return $this->counterparty?->id();
    }

    public function decidedBy(): ?string
    {
        return $this->decidedBy;
    }

    public function decidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Whether a person decided, which Cash flow never overrides.
     */
    public function isDecided(): bool
    {
        return PairingState::Confirmed === $this->state || PairingState::Declined === $this->state;
    }

    private function point(?PlannedItem $item, ?\DateTimeImmutable $on): void
    {
        $this->plannedItem = $item;
        $this->occurrenceOn = $on;
    }

    private function decide(string $userId, \DateTimeImmutable $now): void
    {
        $this->decidedBy = $userId;
        $this->decidedAt = $now;
        $this->updatedAt = $now;
    }

    private function denyWhenDecided(): void
    {
        if ($this->isDecided()) {
            throw new \LogicException('A person decided this pairing; only a person changes it.');
        }
    }
}
