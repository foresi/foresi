<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * A chance to sell something to a client: its title, the owner who works on
 * it, the stage it is at and whether it is open, won or lost. Only an open
 * deal moves between stages and is won or lost; a won or lost one is
 * reopened first, which forgets when and why it closed. Its lines, contact
 * persons and links are kept beside it, by its id. Going to the trash keeps
 * everything, and restoring brings it back as it was.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_deal')]
#[ORM\Index(name: 'idx_sales_deal_client', columns: ['client_party_id'])]
#[ORM\Index(name: 'idx_sales_deal_stage', columns: ['stage_id'])]
class Deal implements Audited
{
    public const int MAX_TITLE = 200;
    public const int MAX_DESCRIPTION = 10000;
    public const int MAX_LOST_NOTE = 1000;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: self::MAX_TITLE)]
    private string $title;

    /** Markdown. */
    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\Column(name: 'owner_user_id', type: 'guid')]
    private string $ownerUserId;

    #[ORM\Column(name: 'stage_id', type: 'guid')]
    private string $stageId;

    /** A plain string, so a new status needs no data migration. */
    #[ORM\Column(type: 'string', length: 16, enumType: DealStatus::class)]
    private DealStatus $status = DealStatus::Open;

    #[ORM\Column(name: 'won_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $wonAt = null;

    #[ORM\Column(name: 'lost_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lostAt = null;

    #[ORM\Column(name: 'lost_reason_id', type: 'guid', nullable: true)]
    private ?string $lostReasonId = null;

    #[ORM\Column(name: 'lost_note', type: 'string', length: self::MAX_LOST_NOTE, nullable: true)]
    private ?string $lostNote = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'trashed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $trashedAt = null;

    /** Who put it in the trash. */
    #[ORM\Column(name: 'trashed_by', type: 'guid', nullable: true)]
    private ?string $trashedBy = null;

    /**
     * @throws InvalidField
     */
    public function __construct(
        string $title,
        string $description,
        #[ORM\Column(name: 'client_party_id', type: 'guid')]
        private readonly string $clientPartyId,
        string $ownerUserId,
        string $stageId,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->id = Id::generate();
        $this->title = self::title($title);
        $this->description = self::description($description);
        $this->ownerUserId = $ownerUserId;
        $this->stageId = $stageId;
        $this->updatedAt = $createdAt;
    }

    /**
     * @throws InvalidField
     */
    public function describe(string $title, string $description, \DateTimeImmutable $now): void
    {
        $this->title = self::title($title);
        $this->description = self::description($description);
        $this->touch($now);
    }

    public function assignTo(string $ownerUserId, \DateTimeImmutable $now): void
    {
        $this->ownerUserId = $ownerUserId;
        $this->touch($now);
    }

    /**
     * @return bool whether it moved: false when it is at the stage already
     *
     * @throws DealConflict deal_not_open
     */
    public function moveTo(string $stageId, \DateTimeImmutable $now): bool
    {
        $this->requireOpen('Only an open deal changes its stage; reopen it first.');
        if ($stageId === $this->stageId) {
            return false;
        }
        $this->stageId = $stageId;
        $this->touch($now);

        return true;
    }

    /**
     * @throws DealConflict deal_not_open
     */
    public function win(\DateTimeImmutable $now): void
    {
        $this->requireOpen('Only an open deal is won; reopen it first.');
        $this->status = DealStatus::Won;
        $this->wonAt = $now;
        $this->touch($now);
    }

    /**
     * @throws DealConflict deal_not_open
     * @throws InvalidField lost_note_too_long
     */
    public function lose(?string $reasonId, ?string $note, \DateTimeImmutable $now): void
    {
        $this->requireOpen('Only an open deal is lost; reopen it first.');
        $note = null === $note ? null : trim($note);
        if (null !== $note && mb_strlen($note) > self::MAX_LOST_NOTE) {
            throw new InvalidField('note', 'lost_note_too_long', \sprintf('The note is at most %d characters.', self::MAX_LOST_NOTE));
        }
        $this->status = DealStatus::Lost;
        $this->lostAt = $now;
        $this->lostReasonId = $reasonId;
        $this->lostNote = '' === $note ? null : $note;
        $this->touch($now);
    }

    /**
     * Opens a won or lost deal again: when and why it closed are forgotten.
     *
     * @throws DealConflict deal_not_closed
     */
    public function reopen(\DateTimeImmutable $now): void
    {
        if (DealStatus::Open === $this->status) {
            throw new DealConflict('deal_not_closed', 'The deal is open already; only a won or lost deal is reopened.');
        }
        $this->status = DealStatus::Open;
        $this->wonAt = null;
        $this->lostAt = null;
        $this->lostReasonId = null;
        $this->lostNote = null;
        $this->touch($now);
    }

    /**
     * Puts it in the trash; trashing it again changes nothing.
     *
     * @return bool whether it went to the trash now
     */
    public function trash(string $userId, \DateTimeImmutable $now): bool
    {
        if ($this->trashedAt instanceof \DateTimeImmutable) {
            return false;
        }
        $this->trashedAt = $now;
        $this->trashedBy = $userId;
        $this->touch($now);

        return true;
    }

    /**
     * @return bool whether it was in the trash
     */
    public function restore(\DateTimeImmutable $now): bool
    {
        if (!$this->trashedAt instanceof \DateTimeImmutable) {
            return false;
        }
        $this->trashedAt = null;
        $this->trashedBy = null;
        $this->touch($now);

        return true;
    }

    /**
     * Marks a change beside it, such as to its lines or contact persons.
     */
    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function clientPartyId(): string
    {
        return $this->clientPartyId;
    }

    public function ownerUserId(): string
    {
        return $this->ownerUserId;
    }

    public function stageId(): string
    {
        return $this->stageId;
    }

    public function status(): DealStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return DealStatus::Open === $this->status;
    }

    public function wonAt(): ?\DateTimeImmutable
    {
        return $this->wonAt;
    }

    public function lostAt(): ?\DateTimeImmutable
    {
        return $this->lostAt;
    }

    public function lostReasonId(): ?string
    {
        return $this->lostReasonId;
    }

    public function lostNote(): ?string
    {
        return $this->lostNote;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isTrashed(): bool
    {
        return $this->trashedAt instanceof \DateTimeImmutable;
    }

    public function trashedAt(): ?\DateTimeImmutable
    {
        return $this->trashedAt;
    }

    public function trashedBy(): ?string
    {
        return $this->trashedBy;
    }

    /**
     * @throws DealConflict deal_not_open
     */
    private function requireOpen(string $message): void
    {
        if (DealStatus::Open !== $this->status) {
            throw new DealConflict('deal_not_open', $message);
        }
    }

    /**
     * @throws InvalidField
     */
    private static function title(string $title): string
    {
        $title = trim($title);
        if ('' === $title) {
            throw new InvalidField('title', 'title_required', 'A deal needs a title.');
        }
        if (mb_strlen($title) > self::MAX_TITLE) {
            throw new InvalidField('title', 'title_too_long', \sprintf('The title is at most %d characters.', self::MAX_TITLE));
        }

        return $title;
    }

    /**
     * @throws InvalidField
     */
    private static function description(string $description): string
    {
        $description = trim($description);
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            throw new InvalidField('description', 'description_too_long', \sprintf('The description is at most %d characters.', self::MAX_DESCRIPTION));
        }

        return $description;
    }
}
