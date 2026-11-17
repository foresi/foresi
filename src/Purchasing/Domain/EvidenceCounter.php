<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * The last evidence number given in one period of a series. Mapped so the schema
 * is validated, but only the single atomic statements of DoctrineEvidenceNumbers
 * write it and the ORM never loads a row. A technical value, so not audited: the documents explain it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'evidence_counter')]
#[ORM\UniqueConstraint(name: 'uniq_evidence_counter', columns: ['evidence_series_id', 'period'])]
class EvidenceCounter
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
        #[ORM\ManyToOne(targetEntity: EvidenceSeries::class)]
        #[ORM\JoinColumn(name: 'evidence_series_id', nullable: false, onDelete: 'RESTRICT')]
        public readonly EvidenceSeries $series,
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
