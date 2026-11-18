<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Shared\Domain\Id;

/**
 * A firm's own pairing switches, once it changed them. A firm without a row
 * has the defaults: automatic pairing on, small-difference settlement off.
 */
#[ORM\Entity]
#[ORM\Table(name: 'firm_bank_pairing')]
#[ORM\UniqueConstraint(name: 'uniq_firm_bank_pairing_firm', columns: ['legal_entity_id'])]
class FirmPairing implements Audited, AuditRooted
{
    public const bool DEFAULT_AUTO_PAIRING = true;
    public const bool DEFAULT_SMALL_DIFFERENCE = false;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: LegalEntity::class)]
        #[ORM\JoinColumn(name: 'legal_entity_id', nullable: false, onDelete: 'RESTRICT')]
        private readonly LegalEntity $firm,
        #[ORM\Column(name: 'auto_pairing', type: 'boolean', options: ['default' => true])]
        private bool $autoPairing,
        #[ORM\Column(name: 'small_difference', type: 'boolean', options: ['default' => false])]
        private bool $smallDifference,
    ) {
        $this->id = Id::generate();
    }

    public static function of(LegalEntity $firm, bool $autoPairing, bool $smallDifference): self
    {
        return new self($firm, $autoPairing, $smallDifference);
    }

    public function change(bool $autoPairing, bool $smallDifference): void
    {
        $this->autoPairing = $autoPairing;
        $this->smallDifference = $smallDifference;
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

    public function autoPairing(): bool
    {
        return $this->autoPairing;
    }

    public function smallDifference(): bool
    {
        return $this->smallDifference;
    }
}
