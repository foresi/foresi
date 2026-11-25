<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * One stored quote: a currency on a date it was asked for, as one source
 * published it, priced in that source's base, with the day it was published.
 * Two sources keep their quotes of the same day side by side. A rate is the
 * same for everyone. Mapped so the schema is validated, but only the
 * statements of DoctrineRateStore write it and the ORM never loads a row.
 */
#[ORM\Entity]
#[ORM\Table(name: 'exchange_rate')]
class StoredRate
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'rate_date', type: 'date_immutable')]
        public readonly \DateTimeImmutable $date,
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 3, options: ['fixed' => true])]
        public readonly string $currency,
        #[ORM\Column(type: 'integer')]
        public readonly int $amount,
        #[ORM\Column(type: 'decimal', precision: 18, scale: 6)]
        public readonly string $rate,
        #[ORM\Column(name: 'published_on', type: 'date_immutable')]
        public readonly \DateTimeImmutable $publishedOn,
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 16)]
        public readonly string $source,
        #[ORM\Column(type: 'string', length: 3, options: ['fixed' => true, 'default' => 'CZK'])]
        public readonly string $base = 'CZK',
    ) {
    }
}
