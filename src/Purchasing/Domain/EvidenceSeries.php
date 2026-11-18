<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Shared\Domain\Id;

/**
 * The series that numbers the received documents of a firm in its own books,
 * apart from the numbers the suppliers gave them. One per firm; the numbers come
 * from EvidenceNumbers and are never reused.
 */
#[ORM\Entity]
#[ORM\Table(name: 'evidence_series')]
#[ORM\UniqueConstraint(name: 'uniq_evidence_series_firm', columns: ['legal_entity_id'])]
class EvidenceSeries implements Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: EvidenceNumberFormat::MAX_LENGTH)]
    private string $format;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        EvidenceNumberFormat $format,
    ) {
        $this->id = Id::generate();
        $this->format = $format->toString();
    }

    public static function standard(LegalEntity $firm): self
    {
        return new self($firm, EvidenceNumberFormat::standard());
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
