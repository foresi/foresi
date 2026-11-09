<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

use App\Identity\Domain\Membership;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * How one person of the organisation wants to hear about their notifications
 * by e-mail: per kind of event at once, in the daily digest or not at all
 * (the channel × event matrix of Linear, ClickUp and Asana), the hour of the
 * digest in the organisation's zone, and a switch for no e-mail at all. The
 * inbox always has every notification, so it has no setting.
 *
 * Only what is addressed to the person, a mention and becoming responsible,
 * may come at once; the rest is news of a followed task, for the digest. A
 * person with no row has the defaults.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_preference')]
#[ORM\Index(name: 'idx_notification_preference_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_notification_preference_membership', columns: ['membership_id'])]
class NotificationPreference implements TenantOwned
{
    public const int DEFAULT_DIGEST_HOUR = 8;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: 16, enumType: EmailDelivery::class)]
    private EmailDelivery $mentioned;

    #[ORM\Column(type: 'string', length: 16, enumType: EmailDelivery::class)]
    private EmailDelivery $assigned;

    #[ORM\Column(type: 'string', length: 16, enumType: EmailDelivery::class)]
    private EmailDelivery $commented;

    #[ORM\Column(name: 'status_changed', type: 'string', length: 16, enumType: EmailDelivery::class)]
    private EmailDelivery $statusChanged;

    #[ORM\Column(type: 'string', length: 16, enumType: EmailDelivery::class)]
    private EmailDelivery $shared;

    /** The local hour of the digest in the organisation's zone, 0 to 23. */
    #[ORM\Column(name: 'digest_hour', type: 'smallint')]
    private int $digestHour = self::DEFAULT_DIGEST_HOUR;

    #[ORM\Column(name: 'no_email', type: 'boolean')]
    private bool $noEmail = false;

    #[ORM\Column(name: 'last_digest_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastDigestAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Membership::class)]
        #[ORM\JoinColumn(name: 'membership_id', nullable: false, onDelete: 'CASCADE')]
        private readonly Membership $membership,
    ) {
        $this->id = Id::generate();
        $this->mentioned = self::defaultFor(NotificationKind::Mentioned);
        $this->assigned = self::defaultFor(NotificationKind::Assigned);
        $this->commented = self::defaultFor(NotificationKind::Commented);
        $this->statusChanged = self::defaultFor(NotificationKind::StatusChanged);
        $this->shared = self::defaultFor(NotificationKind::Shared);
    }

    /**
     * At once what is addressed to the person, the rest in the digest.
     */
    public static function defaultFor(NotificationKind $kind): EmailDelivery
    {
        return $kind->isDirect() ? EmailDelivery::Immediate : EmailDelivery::Digest;
    }

    /**
     * Whether the kind may be mailed at once: only a mention and becoming responsible.
     */
    public static function allows(NotificationKind $kind, EmailDelivery $delivery): bool
    {
        return EmailDelivery::Immediate !== $delivery || $kind->isDirect();
    }

    /**
     * @throws \DomainException when the kind cannot be mailed at once
     */
    public function change(NotificationKind $kind, EmailDelivery $delivery): void
    {
        if (!self::allows($kind, $delivery)) {
            throw new \DomainException(sprintf('Only a mention or an assignment is mailed at once, not "%s".', $kind->value));
        }
        match ($kind) {
            NotificationKind::Mentioned => $this->mentioned = $delivery,
            NotificationKind::Assigned => $this->assigned = $delivery,
            NotificationKind::Commented => $this->commented = $delivery,
            NotificationKind::StatusChanged => $this->statusChanged = $delivery,
            NotificationKind::Shared => $this->shared = $delivery,
        };
    }

    /**
     * @throws \DomainException when the hour is not 0 to 23
     */
    public function changeDigestHour(int $hour): void
    {
        if ($hour < 0 || $hour > 23) {
            throw new \DomainException(sprintf('The digest comes at an hour from 0 to 23, not %d.', $hour));
        }
        $this->digestHour = $hour;
    }

    public function stopEmails(bool $noEmail): void
    {
        $this->noEmail = $noEmail;
    }

    /**
     * The link at the foot of the digest: no kind comes in a digest any more.
     */
    public function stopDigest(): void
    {
        foreach (NotificationKind::cases() as $kind) {
            if (EmailDelivery::Digest === $this->delivery($kind)) {
                $this->change($kind, EmailDelivery::Off);
            }
        }
    }

    public function digestSent(\DateTimeImmutable $now): void
    {
        $this->lastDigestAt = $now;
    }

    /**
     * The choice for the kind, whatever the switch for no e-mail says.
     */
    public function delivery(NotificationKind $kind): EmailDelivery
    {
        return match ($kind) {
            NotificationKind::Mentioned => $this->mentioned,
            NotificationKind::Assigned => $this->assigned,
            NotificationKind::Commented => $this->commented,
            NotificationKind::StatusChanged => $this->statusChanged,
            NotificationKind::Shared => $this->shared,
        };
    }

    /**
     * How the kind is mailed now: never while the person wants no e-mail.
     */
    public function mails(NotificationKind $kind): EmailDelivery
    {
        return $this->noEmail ? EmailDelivery::Off : $this->delivery($kind);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function membership(): Membership
    {
        return $this->membership;
    }

    public function digestHour(): int
    {
        return $this->digestHour;
    }

    public function wantsNoEmail(): bool
    {
        return $this->noEmail;
    }

    public function lastDigestAt(): ?\DateTimeImmutable
    {
        return $this->lastDigestAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
