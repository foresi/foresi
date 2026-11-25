<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;

/**
 * The source the firm of the instance chose for its exchange rates. One row
 * with a constant key (ADR 0039: one firm per instance); without it the
 * default of the firm's country applies (ADR 0046).
 */
#[ORM\Entity]
#[ORM\Table(name: 'exchange_rate_setting')]
class RateSourceSetting implements Audited
{
    public const int ID = 1;
    public const int MAX_SOURCE = 16;

    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    private int $id = self::ID;

    public function __construct(
        #[ORM\Column(type: 'string', length: self::MAX_SOURCE)]
        private string $source,
    ) {
    }

    public function choose(string $source): void
    {
        $this->source = $source;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function source(): string
    {
        return $this->source;
    }
}
