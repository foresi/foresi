<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Domain;

use App\Shared\Domain\Currency;
use App\Shared\Domain\Id;
use App\Shared\Domain\Money;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A time entry an invoice billed, kept while the invoice is in the trash: the
 * entry itself is unbilled meanwhile, and restoring the invoice bills it again on
 * the same line, at the same rate and for the same minutes. The fingerprint is
 * what the entry said then, so an edit since is told apart. The entry is named by
 * its id alone, as it may be deleted meanwhile.
 */
#[ORM\Entity]
#[ORM\Table(name: 'released_time')]
#[ORM\Index(name: 'idx_released_time_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_released_time', columns: ['invoice_id', 'time_entry_id'])]
class ReleasedTime implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(name: 'rate_minor', type: 'bigint')]
    private int $rateMinor;

    #[ORM\Column(name: 'rate_currency', type: 'string', length: 3, enumType: Currency::class, options: ['fixed' => true])]
    private Currency $rateCurrency;

    public function __construct(
        #[ORM\Column(name: 'invoice_id', type: 'guid')]
        private readonly string $invoiceId,
        #[ORM\Column(name: 'time_entry_id', type: 'guid')]
        private readonly string $entryId,
        #[ORM\Column(name: 'invoice_line_id', type: 'guid')]
        private readonly string $lineId,
        Money $rate,
        #[ORM\Column(type: 'integer')]
        private readonly int $minutes,
        #[ORM\Column(type: 'string', length: 32)]
        private readonly string $fingerprint,
        #[ORM\Column(name: 'released_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $releasedAt,
    ) {
        $this->id = Id::generate();
        $this->rateMinor = $rate->minor();
        $this->rateCurrency = $rate->currency();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function invoiceId(): string
    {
        return $this->invoiceId;
    }

    public function entryId(): string
    {
        return $this->entryId;
    }

    public function lineId(): string
    {
        return $this->lineId;
    }

    public function rate(): Money
    {
        return Money::ofMinor($this->rateMinor, $this->rateCurrency);
    }

    public function minutes(): int
    {
        return $this->minutes;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function releasedAt(): \DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
