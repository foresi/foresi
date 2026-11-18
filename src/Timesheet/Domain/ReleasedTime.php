<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;

/**
 * A time entry an invoice billed, kept while the invoice is in the trash: the
 * entry itself is unbilled meanwhile, and restoring the invoice bills it again on
 * the same line, at the same rate and for the same minutes. The fingerprint is
 * what the entry said then, so an edit since is told apart. The entry is named by
 * its id alone, as it may be deleted meanwhile.
 */
#[ORM\Entity]
#[ORM\Table(name: 'released_time')]
#[ORM\UniqueConstraint(name: 'uniq_released_time', columns: ['invoice_id', 'time_entry_id'])]
class ReleasedTime
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

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
}
