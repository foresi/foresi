<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * The last sign of life of a part of the installation, such as the worker or the
 * schedule, one row per name. Not owned by a tenant: the installation has one
 * worker. Mapped so the schema is validated, but only the single statements of
 * DoctrineHeartbeats write it and the ORM never loads a row.
 */
#[ORM\Entity]
#[ORM\Table(name: 'heartbeat')]
class Heartbeat
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        public readonly string $name,
        #[ORM\Column(name: 'beat_at', type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $beatAt,
    ) {
    }
}
