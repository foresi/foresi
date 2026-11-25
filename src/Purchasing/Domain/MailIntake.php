<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * One mail taken in by a capture address, and what came of it: the log of this
 * purpose. It has the id of the Mail message, which is what keeps one message
 * from being taken in twice, and the row the job locks while it files the mail.
 * It holds the sender's verdict as Mail judged it on arrival, the attachments
 * already made into drafts (by their index in the mail), the number of drafts
 * and, for each attachment left out, its name and the reason. Never a sender's
 * address or the text of a mail.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchasing_mail_intake')]
#[ORM\Index(name: 'idx_purchasing_mail_intake_owner', columns: ['owner_user_id', 'created_at'])]
class MailIntake
{
    /** Attachments are listed up to this many; the rest are only counted. */
    public const int MAX_SKIPPED = 50;
    public const int MAX_NAME_LENGTH = 200;

    #[ORM\Column(type: 'string', length: 16, enumType: MailIntakeOutcome::class)]
    private MailIntakeOutcome $outcome = MailIntakeOutcome::Queued;

    #[ORM\Column(type: 'integer')]
    private int $drafts = 0;

    /** @var list<int> the indexes in the mail of the attachments made into drafts */
    #[ORM\Column(type: 'json')]
    private array $filed = [];

    /** @var list<array{name: string, reason: string}> */
    #[ORM\Column(type: 'json')]
    private array $skipped = [];

    #[ORM\Column(name: 'skipped_count', type: 'integer')]
    private int $skippedCount = 0;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /**
     * @param string $verdict the sender check of Mail: verified, failed or unknown
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private string $id,
        #[ORM\Column(name: 'owner_user_id', type: 'guid')]
        private string $ownerUserId,
        /** The inbound address of Mail the mail came to. */
        #[ORM\Column(name: 'mail_address_id', type: 'guid')]
        private string $mailAddressId,
        #[ORM\Column(type: 'string', length: 16)]
        private string $verdict,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public function isQueued(): bool
    {
        return MailIntakeOutcome::Queued === $this->outcome;
    }

    public function hasFiled(int $attachmentIndex): bool
    {
        return \in_array($attachmentIndex, $this->filed, true);
    }

    /**
     * The attachment at this index of the mail is now a draft; stored in the
     * same transaction as the draft, so it is never made twice.
     */
    public function markFiled(int $attachmentIndex): void
    {
        if (!$this->hasFiled($attachmentIndex)) {
            $this->filed[] = $attachmentIndex;
        }
    }

    /**
     * The mail made $drafts drafts, or none; $skipped are the attachments left out.
     *
     * @param list<array{name: string, reason: string}> $skipped
     */
    public function finish(int $drafts, array $skipped, \DateTimeImmutable $at): void
    {
        if (!$this->isQueued()) {
            return;
        }
        $this->drafts = $drafts;
        $this->skippedCount = \count($skipped);
        $this->skipped = array_map(
            static fn (array $part): array => ['name' => mb_substr($part['name'], 0, self::MAX_NAME_LENGTH), 'reason' => $part['reason']],
            \array_slice($skipped, 0, self::MAX_SKIPPED),
        );
        $this->outcome = $drafts > 0 ? MailIntakeOutcome::Filed : MailIntakeOutcome::NothingAccepted;
        $this->finishedAt = $at;
    }

    public function fail(\DateTimeImmutable $at): void
    {
        if (!$this->isQueued()) {
            return;
        }
        $this->outcome = MailIntakeOutcome::Failed;
        $this->finishedAt = $at;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function ownerUserId(): string
    {
        return $this->ownerUserId;
    }

    public function mailAddressId(): string
    {
        return $this->mailAddressId;
    }

    public function verdict(): string
    {
        return $this->verdict;
    }

    public function outcome(): MailIntakeOutcome
    {
        return $this->outcome;
    }

    public function drafts(): int
    {
        return $this->drafts;
    }

    /**
     * @return list<array{name: string, reason: string}>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    public function skippedCount(): int
    {
        return $this->skippedCount;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }
}
