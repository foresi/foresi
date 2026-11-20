<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Invoicing\Contract\TotalRounding;
use Foresi\Shared\Domain\Id;

/**
 * What the firm prints on its invoices and how it rounds them. One row for the
 * instance; without a row the standard() settings apply, and they are not stored
 * until they are changed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'firm_invoicing')]
#[ORM\UniqueConstraint(name: 'uniq_firm_invoicing_one_row', columns: ['one_row'])]
class FirmInvoicing implements Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /**
     * Always true: with its unique index (and a check on PostgreSQL) the table holds
     * at most one row, the settings of the instance's one firm (ADR 0039).
     */
    #[ORM\Column(name: 'one_row', type: 'boolean', options: ['default' => true])]
    private bool $oneRow = true;

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

    private function __construct()
    {
        $this->id = Id::generate();
    }

    public static function standard(): self
    {
        return new self();
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
