<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Audit\Fixtures;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditExcluded;
use App\Shared\Domain\Id;
use Doctrine\ORM\Mapping as ORM;

/**
 * Test-only audited entity with a field that must never reach the log.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_audited_secret')]
class AuditedSecret implements Audited
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        private string $label,
        #[ORM\Column(type: 'string', length: 64)]
        #[AuditExcluded]
        private string $secret,
    ) {
        $this->id = Id::generate();
    }

    public function changeSecret(string $secret): void
    {
        $this->secret = $secret;
    }

    public function changeLabel(string $label): void
    {
        $this->label = $label;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function secret(): string
    {
        return $this->secret;
    }
}
