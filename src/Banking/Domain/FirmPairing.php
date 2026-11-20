<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Shared\Domain\Id;

/**
 * The pairing switches of the instance, once they were changed: the table holds
 * at most one row. Without a row the defaults apply: automatic pairing on,
 * small-difference settlement off.
 */
#[ORM\Entity]
#[ORM\Table(name: 'firm_bank_pairing')]
#[ORM\UniqueConstraint(name: 'uniq_firm_bank_pairing_one_row', columns: ['one_row'])]
class FirmPairing implements Audited
{
    public const bool DEFAULT_AUTO_PAIRING = true;
    public const bool DEFAULT_SMALL_DIFFERENCE = false;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    /**
     * Always true: with its unique index (and a check on PostgreSQL) the table holds
     * at most one row, the settings of the instance's one firm (ADR 0039).
     */
    #[ORM\Column(name: 'one_row', type: 'boolean', options: ['default' => true])]
    private bool $oneRow = true;

    private function __construct(
        #[ORM\Column(name: 'auto_pairing', type: 'boolean', options: ['default' => true])]
        private bool $autoPairing,
        #[ORM\Column(name: 'small_difference', type: 'boolean', options: ['default' => false])]
        private bool $smallDifference,
    ) {
        $this->id = Id::generate();
    }

    public static function of(bool $autoPairing, bool $smallDifference): self
    {
        return new self($autoPairing, $smallDifference);
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

    public function autoPairing(): bool
    {
        return $this->autoPairing;
    }

    public function smallDifference(): bool
    {
        return $this->smallDifference;
    }
}
