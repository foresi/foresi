<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * The series that numbers the received documents of the firm in its own books,
 * apart from the numbers the suppliers gave them. One per instance (ADR 0039); the
 * numbers come from EvidenceNumbers and are never reused.
 */
#[ORM\Entity]
#[ORM\Table(name: 'evidence_series')]
#[ORM\UniqueConstraint(name: 'uniq_evidence_series_one_row', columns: ['one_row'])]
class EvidenceSeries implements Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: EvidenceNumberFormat::MAX_LENGTH)]
    private string $format;

    /**
     * Always true: with its unique index (and a check on PostgreSQL) the table holds
     * at most one row, the settings of the instance's one firm (ADR 0039).
     */
    #[ORM\Column(name: 'one_row', type: 'boolean', options: ['default' => true])]
    private bool $oneRow = true;

    public function __construct(
        EvidenceNumberFormat $format,
    ) {
        $this->id = Id::generate();
        $this->format = $format->toString();
    }

    public static function standard(): self
    {
        return new self(EvidenceNumberFormat::standard());
    }

    public function id(): string
    {
        return $this->id;
    }

    public function format(): EvidenceNumberFormat
    {
        return EvidenceNumberFormat::fromString($this->format);
    }

    /**
     * A new format applies to the numbers given from now on; the numbers given
     * keep their form, and a format that could repeat one is the caller's to refuse.
     */
    public function changeFormat(EvidenceNumberFormat $format): void
    {
        $this->format = $format->toString();
    }
}
