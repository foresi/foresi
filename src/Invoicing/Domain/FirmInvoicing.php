<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Shared\Domain\Id;

/**
 * What a firm prints on its invoices and how it rounds them. One row per firm; a
 * firm without a row uses standard(), which is not stored until it is changed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'firm_invoicing')]
#[ORM\UniqueConstraint(name: 'uniq_firm_invoicing_firm', columns: ['legal_entity_id'])]
class FirmInvoicing implements Audited, AuditRooted
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 16, enumType: TotalRounding::class)]
    private TotalRounding $rounding = TotalRounding::WholeUnit;

    #[ORM\Column(name: 'constant_symbol', type: 'string', length: 10)]
    private string $constantSymbol = '';

    #[ORM\Column(name: 'text_before', type: 'text')]
    private string $textBefore = '';

    #[ORM\Column(name: 'text_after', type: 'text')]
    private string $textAfter = '';

    #[ORM\Column(name: 'contact_email', type: 'string', length: 255)]
    private string $contactEmail = '';

    #[ORM\Column(name: 'contact_phone', type: 'string', length: 32)]
    private string $contactPhone = '';

    #[ORM\Column(name: 'contact_web', type: 'string', length: 255)]
    private string $contactWeb = '';

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

    public function change(TotalRounding $rounding, string $constantSymbol, string $textBefore, string $textAfter, string $contactEmail, string $contactPhone, string $contactWeb): void
    {
        $this->rounding = $rounding;
        $this->constantSymbol = $constantSymbol;
        $this->textBefore = $textBefore;
        $this->textAfter = $textAfter;
        $this->contactEmail = $contactEmail;
        $this->contactPhone = $contactPhone;
        $this->contactWeb = $contactWeb;
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

    public function rounding(): TotalRounding
    {
        return $this->rounding;
    }

    public function constantSymbol(): string
    {
        return $this->constantSymbol;
    }

    public function textBefore(): string
    {
        return $this->textBefore;
    }

    public function textAfter(): string
    {
        return $this->textAfter;
    }

    public function contactEmail(): string
    {
        return $this->contactEmail;
    }

    public function contactPhone(): string
    {
        return $this->contactPhone;
    }

    public function contactWeb(): string
    {
        return $this->contactWeb;
    }
}
