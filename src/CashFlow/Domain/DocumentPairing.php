<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\CashFlow\Contract\PairingState;
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

    #[ORM\Column(name: 'planned_item_id', type: 'guid', nullable: true)]
    private ?string $plannedItemId = null;

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
        #[ORM\Column(name: 'counterparty_id', type: 'guid', nullable: true)]
        private ?string $counterpartyId,
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
    public static function auto(string $subjectType, string $subjectId, ?string $counterpartyId, PairingCandidate $candidate, \DateTimeImmutable $now): self
    {
        $pairing = new self($subjectType, $subjectId, $counterpartyId, $now);
        $pairing->becomeAuto($candidate, $counterpartyId, $now);

        return $pairing;
    }

    /**
     * A document that may belong to one of the candidates, for a person to decide.
     *
     * @param list<PairingCandidate> $candidates at least one
     */
    public static function propose(string $subjectType, string $subjectId, ?string $counterpartyId, array $candidates, \DateTimeImmutable $now): self
    {
        $pairing = new self($subjectType, $subjectId, $counterpartyId, $now);
        $pairing->becomeProposed($candidates, $counterpartyId, $now);

        return $pairing;
    }

    /**
     * Cash flow pairs the document with the occurrence on its own.
     *
     * @throws \LogicException when a person decided already
     */
    public function becomeAuto(PairingCandidate $candidate, ?string $counterpartyId, \DateTimeImmutable $now): void
    {
        $this->denyWhenDecided();
        $this->state = PairingState::Auto;
        $this->point($candidate);
        $this->candidates = [$candidate->toArray()];
        $this->counterpartyId = $counterpartyId;
        $this->updatedAt = $now;
    }

    /**
     * Cash flow offers the candidates to a person. One candidate is also the occurrence.
     *
     * @param list<PairingCandidate> $candidates at least one
     *
     * @throws \LogicException when a person decided already, or no candidate is given
     */
    public function becomeProposed(array $candidates, ?string $counterpartyId, \DateTimeImmutable $now): void
    {
        $this->denyWhenDecided();
        if ([] === $candidates) {
            throw new \LogicException('A proposal needs a candidate.');
        }
        $this->state = PairingState::Proposed;
        $this->point(1 === \count($candidates) ? $candidates[0] : null);
        $this->candidates = array_map(static fn (PairingCandidate $candidate): array => $candidate->toArray(), $candidates);
        $this->counterpartyId = $counterpartyId;
        $this->updatedAt = $now;
    }

    /**
     * A person says the document belongs to this occurrence. The candidates stay.
     */
    public function confirm(PairingCandidate $candidate, string $userId, \DateTimeImmutable $now): void
    {
        $this->state = PairingState::Confirmed;
        $this->point($candidate);
        $this->decide($userId, $now);
    }

    /**
     * A person says the document belongs to none of the candidates. The candidates stay.
     */
    public function decline(string $userId, \DateTimeImmutable $now): void
    {
        $this->state = PairingState::Declined;
        $this->point(null);
        $this->decide($userId, $now);
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
        return $this->plannedItemId;
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
        return $this->counterpartyId;
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

    private function point(?PairingCandidate $candidate): void
    {
        $this->plannedItemId = $candidate?->plannedItemId;
        $this->occurrenceOn = $candidate?->occurrenceOn;
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
