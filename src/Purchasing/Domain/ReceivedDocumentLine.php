<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Shared\Domain\Money;

/**
 * A line of a received document, audited under it: what part of the cost it
 * was for, its base in the document's currency and the VAT rate of the recap
 * it falls under, as the recap writes the rate. The lines split the bases of
 * the recap; they never change the recap, which stays the only source of VAT.
 * The document checks and keeps them (DocumentLines), rewriting them in place,
 * so a line keeps its id and position and only the last ones come and go.
 */
#[ORM\Entity]
#[ORM\Table(name: 'received_document_line')]
#[ORM\Index(name: 'idx_received_document_line_document', columns: ['document_id'])]
#[ORM\UniqueConstraint(name: 'uniq_received_document_line_position', columns: ['document_id', 'position'])]
class ReceivedDocumentLine implements Audited, AuditRooted
{
    #[ORM\Column(name: 'base_minor', type: 'bigint')]
    private int $baseMinor;

    /**
     * Only the document makes its lines, from lines DocumentLines checked.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private readonly string $id,
        #[ORM\ManyToOne(targetEntity: ReceivedDocument::class, inversedBy: 'lines')]
        #[ORM\JoinColumn(name: 'document_id', nullable: false, onDelete: 'CASCADE')]
        private readonly ReceivedDocument $document,
        #[ORM\Column(type: 'integer')]
        private readonly int $position,
        #[ORM\Column(type: 'string', length: DocumentLines::MAX_TEXT_LENGTH)]
        private string $text,
        Money $base,
        #[ORM\Column(name: 'vat_rate', type: 'string', length: 6)]
        private string $vatRate,
    ) {
        $this->baseMinor = $base->minor();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->document;
    }

    public function document(): ReceivedDocument
    {
        return $this->document;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function base(): Money
    {
        return Money::ofMinor($this->baseMinor, $this->document->currency());
    }

    public function vatRate(): string
    {
        return $this->vatRate;
    }

    /**
     * The line as DocumentLines checks it.
     */
    public function toInput(): ReceivedDocumentLineInput
    {
        return new ReceivedDocumentLineInput($this->text, $this->base(), $this->vatRate);
    }

    /**
     * Takes the values of a checked line in its place.
     */
    public function rewrite(ReceivedDocumentLineInput $line): void
    {
        $this->text = $line->text;
        $this->baseMinor = $line->base->minor();
        $this->vatRate = $line->vatRate;
    }
}
