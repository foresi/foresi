<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * The last number given in one period of a series. Mapped so the schema is
 * validated, but only the single atomic
 * statements of the DoctrineNumberCounter write it and the ORM never loads a row.
 * A technical value, so not audited: the audited Series and documents explain it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'series_counter')]
#[ORM\UniqueConstraint(name: 'uniq_series_counter', columns: ['series_id', 'period'])]
class SeriesCounter
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
        #[ORM\ManyToOne(targetEntity: Series::class)]
        #[ORM\JoinColumn(name: 'series_id', nullable: false, onDelete: 'RESTRICT')]
        public readonly Series $series,
        #[ORM\Column(type: 'string', length: 8)]
        public readonly string $period,
        #[ORM\Column(name: 'last_value', type: 'integer')]
        public readonly int $lastValue,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }
}
