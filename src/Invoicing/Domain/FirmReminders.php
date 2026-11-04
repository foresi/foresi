<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditRooted;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * Whether and when a firm reminds its customers of overdue invoices. The first
 * reminder goes a number of days after the due date, each next one a number of
 * days after the previous, up to MAX_STEPS. Only invoices due on or after the day
 * reminders were switched on are reminded, so switching on never sends a wave
 * about old invoices. The minimum is in the firm's home currency; an invoice in
 * another currency is reminded whatever its amount. One row per firm; a firm
 * without one has standard(), switched off, until it is changed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'firm_reminders')]
#[ORM\Index(name: 'idx_firm_reminders_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_firm_reminders_firm', columns: ['legal_entity_id'])]
class FirmReminders implements TenantOwned, Audited, AuditRooted
{
    public const int MAX_STEPS = 10;
    public const int MIN_DAYS = 1;
    public const int MAX_DAYS = 365;
    public const int DEFAULT_DAYS = 7;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $enabled = false;

    /** The firm's day it was last switched on; it stays when switched off. */
    #[ORM\Column(name: 'enabled_on', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $enabledOn = null;

    #[ORM\Column(name: 'first_after_days', type: 'smallint')]
    private int $firstAfterDays = self::DEFAULT_DAYS;

    #[ORM\Column(name: 'every_days', type: 'smallint')]
    private int $everyDays = self::DEFAULT_DAYS;

    #[ORM\Column(name: 'minimum_minor', type: 'bigint', nullable: true)]
    private ?int $minimumMinor = null;

    #[ORM\Column(name: 'minimum_currency', type: 'string', length: 3, nullable: true, enumType: Currency::class, options: ['fixed' => true])]
    private ?Currency $minimumCurrency = null;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
    ) {
        $this->id = Id::generate();
    }

    public static function standard(LegalEntity $firm): self
    {
        return new self($firm);
    }

    /**
     * @param \DateTimeImmutable $today the firm's own day, recorded when this switches reminders on
     *
     * @throws \DomainException for days outside 1 to 365 or a minimum that is not above zero
     */
    public function change(bool $enabled, int $firstAfterDays, int $everyDays, ?Money $minimum, \DateTimeImmutable $today): void
    {
        foreach ([$firstAfterDays, $everyDays] as $days) {
            if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
                throw new \DomainException(\sprintf('Reminders are from %d to %d days apart.', self::MIN_DAYS, self::MAX_DAYS));
            }
        }
        if ($minimum instanceof Money && ($minimum->isZero() || $minimum->isNegative())) {
            throw new \DomainException('A minimum for reminders is above zero.');
        }

        if ($enabled && !$this->enabled) {
            $this->enabledOn = new \DateTimeImmutable($today->format('Y-m-d'));
        }
        $this->enabled = $enabled;
        $this->firstAfterDays = $firstAfterDays;
        $this->everyDays = $everyDays;
        $this->minimumMinor = $minimum?->minor();
        $this->minimumCurrency = $minimum?->currency();
    }

    /**
     * Whether an open amount is worth a reminder: above zero, and at least the
     * minimum when it is in the minimum's currency.
     */
    public function isWorthReminding(Money $outstanding): bool
    {
        if ($outstanding->isZero() || $outstanding->isNegative()) {
            return false;
        }
        $minimum = $this->minimum();

        return !$minimum instanceof Money || $minimum->currency() !== $outstanding->currency() || $outstanding->minor() >= $minimum->minor();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->firm;
    }

    public function firm(): LegalEntity
    {
        return $this->firm;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function enabledOn(): ?\DateTimeImmutable
    {
        return $this->enabledOn;
    }

    public function firstAfterDays(): int
    {
        return $this->firstAfterDays;
    }

    public function everyDays(): int
    {
        return $this->everyDays;
    }

    public function minimum(): ?Money
    {
        return null === $this->minimumMinor || !$this->minimumCurrency instanceof Currency ? null : Money::ofMinor($this->minimumMinor, $this->minimumCurrency);
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
