<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * One mail that arrived at a person's inbox address, as that person deals with
 * it: the contact it is about, whether it is new, done or discarded, and what
 * came of it (ItemOutput). The mail itself stays with Mail, read by its id; the
 * item holds no sender text but the address a forward names.
 *
 * Only its owner sees it. A forward from the owner's own address is matched by
 * the original sender its forward block names, which nobody verified. Going to
 * the trash keeps everything, and restoring brings it back as it was.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inbox_item')]
#[ORM\UniqueConstraint(name: 'uniq_inbox_item_mail_message', columns: ['mail_message_id'])]
#[ORM\Index(name: 'idx_inbox_item_owner_state', columns: ['owner_user_id', 'state', 'received_at'])]
#[ORM\Index(name: 'idx_inbox_item_owner_thread', columns: ['owner_user_id', 'thread_key'])]
class InboxItem implements Audited
{
    public const int MAX_ORIGINAL_FROM = ForwardedSender::MAX_ADDRESS;
    public const int MAX_PURPOSE = 40;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /** The SHA-256 the mails of one conversation share (ThreadKey). */
    #[ORM\Column(name: 'thread_key', type: 'string', length: ThreadKey::LENGTH, options: ['fixed' => true])]
    private string $threadKey;

    #[ORM\Column(type: 'string', length: 16, enumType: ItemState::class)]
    private ItemState $state = ItemState::New;

    /** When the state last changed; the time it arrived while it is new. */
    #[ORM\Column(name: 'state_changed_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $stateChangedAt;

    /** Who changed the state last; nobody while it is new as it came. */
    #[ORM\Column(name: 'state_changed_by', type: 'guid', nullable: true)]
    private ?string $stateChangedBy = null;

    #[ORM\Column(name: 'contact_id', type: 'guid', nullable: true)]
    private ?string $contactId = null;

    /** The company of a matched person, when the person has exactly one. */
    #[ORM\Column(name: 'company_id', type: 'guid', nullable: true)]
    private ?string $companyId = null;

    #[ORM\Column(name: 'match_basis', type: 'string', length: 16, nullable: true, enumType: MatchBasis::class)]
    private ?MatchBasis $matchBasis = null;

    /** The one e-mail activity of the item, made on its first output or link. */
    #[ORM\Column(name: 'email_activity_id', type: 'guid', nullable: true)]
    private ?string $emailActivityId = null;

    #[ORM\Column(name: 'trashed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $trashedAt = null;

    /** Who put it in the trash. */
    #[ORM\Column(name: 'trashed_by', type: 'guid', nullable: true)]
    private ?string $trashedBy = null;

    /**
     * @param string             $mailMessageId      the id of the stored message in Mail
     * @param \DateTimeImmutable $receivedAt         when Mail received it
     * @param string|null        $originalFrom       the original sender a forward names, lower case
     * @param string|null        $duplicateOfPurpose the purpose the same mail arrived under before, such as bank_feed
     */
    public function __construct(
        string $id,
        #[ORM\Column(name: 'mail_message_id', type: 'guid')]
        private string $mailMessageId,
        #[ORM\Column(name: 'owner_user_id', type: 'guid')]
        private string $ownerUserId,
        #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
        private \DateTimeImmutable $receivedAt,
        #[ORM\Column(type: 'boolean')]
        private bool $forwarded,
        #[ORM\Column(name: 'original_from', type: 'string', length: self::MAX_ORIGINAL_FROM, nullable: true)]
        private ?string $originalFrom,
        ThreadKey $threadKey,
        #[ORM\Column(name: 'duplicate_of_purpose', type: 'string', length: self::MAX_PURPOSE, nullable: true)]
        private ?string $duplicateOfPurpose,
    ) {
        if (!Id::isValid($id)) {
            throw new \InvalidArgumentException('An inbox item has a UUIDv7 id.');
        }
        if ($forwarded !== (null !== $originalFrom)) {
            throw new \InvalidArgumentException('A forwarded item, and only one, names its original sender.');
        }
        if (null !== $duplicateOfPurpose && ('' === $duplicateOfPurpose || \strlen($duplicateOfPurpose) > self::MAX_PURPOSE)) {
            throw new \InvalidArgumentException('A purpose key has 1 to 40 characters.');
        }
        $this->id = $id;
        $this->receivedAt = $this->utc($receivedAt);
        $this->stateChangedAt = $this->receivedAt;
        $this->threadKey = $threadKey->value;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function mailMessageId(): string
    {
        return $this->mailMessageId;
    }

    public function ownerUserId(): string
    {
        return $this->ownerUserId;
    }

    public function isOwnedBy(?string $userId): bool
    {
        return null !== $userId && $userId === $this->ownerUserId;
    }

    public function receivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function state(): ItemState
    {
        return $this->state;
    }

    public function stateChangedAt(): \DateTimeImmutable
    {
        return $this->stateChangedAt;
    }

    public function stateChangedBy(): ?string
    {
        return $this->stateChangedBy;
    }

    public function contactId(): ?string
    {
        return $this->contactId;
    }

    public function companyId(): ?string
    {
        return $this->companyId;
    }

    public function matchBasis(): ?MatchBasis
    {
        return $this->matchBasis;
    }

    public function isForwarded(): bool
    {
        return $this->forwarded;
    }

    public function originalFrom(): ?string
    {
        return $this->originalFrom;
    }

    public function threadKey(): string
    {
        return $this->threadKey;
    }

    public function duplicateOfPurpose(): ?string
    {
        return $this->duplicateOfPurpose;
    }

    public function emailActivityId(): ?string
    {
        return $this->emailActivityId;
    }

    public function trashedAt(): ?\DateTimeImmutable
    {
        return $this->trashedAt;
    }

    public function trashedBy(): ?string
    {
        return $this->trashedBy;
    }

    public function isTrashed(): bool
    {
        return $this->trashedAt instanceof \DateTimeImmutable;
    }

    /**
     * Points the item to a contact, and to the company of a person when known.
     */
    public function match(string $contactId, ?string $companyId, MatchBasis $basis): void
    {
        if (!Id::isValid($contactId) || (null !== $companyId && !Id::isValid($companyId))) {
            throw new \InvalidArgumentException('A contact is named by its UUIDv7 id.');
        }
        if ($companyId === $contactId) {
            throw new \InvalidArgumentException('The company of a contact is another contact.');
        }
        $this->contactId = $contactId;
        $this->companyId = $companyId;
        $this->matchBasis = $basis;
    }

    public function unmatch(): void
    {
        $this->contactId = null;
        $this->companyId = null;
        $this->matchBasis = null;
    }

    /**
     * @return bool whether the state changed; the same state again changes nothing
     */
    public function changeState(ItemState $state, string $userId, \DateTimeImmutable $now): bool
    {
        if ($state === $this->state) {
            return false;
        }
        $this->state = $state;
        $this->stateChangedAt = $this->utc($now);
        $this->stateChangedBy = $userId;

        return true;
    }

    public function recordEmailActivity(string $activityId): void
    {
        if (null !== $this->emailActivityId && $activityId !== $this->emailActivityId) {
            throw new \LogicException('An inbox item has one e-mail activity.');
        }
        $this->emailActivityId = $activityId;
    }

    /**
     * @return bool whether it went to the trash; trashing it again changes nothing
     */
    public function trash(string $userId, \DateTimeImmutable $now): bool
    {
        if ($this->isTrashed()) {
            return false;
        }
        $this->trashedAt = $this->utc($now);
        $this->trashedBy = $userId;

        return true;
    }

    /**
     * @return bool whether it came back; restoring one not in the trash changes nothing
     */
    public function restore(): bool
    {
        if (!$this->isTrashed()) {
            return false;
        }
        $this->trashedAt = null;
        $this->trashedBy = null;

        return true;
    }

    private function utc(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->setTimezone(new \DateTimeZone('UTC'));
    }
}
