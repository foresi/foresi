<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;

/**
 * Links a user to the organisation with a role. Any membership may point at a
 * contact (party): the one a guest represents, or a member's own person record;
 * none is required (ADR 0049). A deactivated membership keeps its history but
 * no longer lets the person into the organisation.
 *
 * The person's hourly rate is what their time is billed at when neither the
 * project nor its client has a rate; only owners see it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'membership')]
#[ORM\UniqueConstraint(name: 'uniq_membership_user', columns: ['user_id'])]
class Membership implements Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'disabled_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    #[ORM\Column(name: 'hourly_rate_minor', type: 'bigint', nullable: true)]
    private ?int $hourlyRateMinor = null;

    #[ORM\Column(name: 'hourly_rate_currency', type: 'string', length: 3, nullable: true, enumType: Currency::class, options: ['fixed' => true])]
    private ?Currency $hourlyRateCurrency = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false)]
        private readonly User $user,
        #[ORM\Column(type: 'string', length: 32, enumType: Role::class)]
        private Role $role,
        #[ORM\ManyToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'party_id', nullable: true)]
        private ?Party $party = null,
    ) {
        $this->id = Id::generate();
    }

    /**
     * A new role, with the contact the person represents in it, or none.
     */
    public function changeRole(Role $role, ?Party $party): void
    {
        $this->role = $role;
        $this->party = $party;
    }

    public function deactivate(\DateTimeImmutable $now): void
    {
        $this->disabledAt ??= $now;
    }

    public function reactivate(): void
    {
        $this->disabledAt = null;
    }

    /**
     * Null for none. Zero is a rate, for work that is not charged.
     *
     * @throws \DomainException for a negative rate
     */
    public function changeHourlyRate(?Money $rate): void
    {
        if (true === $rate?->isNegative()) {
            throw new \DomainException('An hourly rate is not negative.');
        }
        $this->hourlyRateMinor = $rate?->minor();
        $this->hourlyRateCurrency = $rate?->currency();
    }

    public function hourlyRate(): ?Money
    {
        return null === $this->hourlyRateMinor || !$this->hourlyRateCurrency instanceof Currency ? null : Money::ofMinor($this->hourlyRateMinor, $this->hourlyRateCurrency);
    }

    public function isActive(): bool
    {
        return !$this->disabledAt instanceof \DateTimeImmutable;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function role(): Role
    {
        return $this->role;
    }

    public function party(): ?Party
    {
        return $this->party;
    }
}
