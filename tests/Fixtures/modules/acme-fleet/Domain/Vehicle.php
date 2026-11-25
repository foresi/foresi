<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Acme\Fleet\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A vehicle of the firm, and the contact who drives it, if anyone does.
 */
#[ORM\Entity]
#[ORM\Table(name: 'acme_fleet_vehicle')]
#[ORM\Index(name: 'acme_fleet_vehicle_driver', columns: ['driver_contact_id'])]
#[ORM\UniqueConstraint(name: 'acme_fleet_vehicle_plate', columns: ['plate'])]
class Vehicle
{
    public const int MAX_NAME = 100;
    public const int MAX_PLATE = 16;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        private string $id,
        #[ORM\Column(type: 'string', length: self::MAX_NAME)]
        private string $name,
        #[ORM\Column(type: 'string', length: self::MAX_PLATE)]
        private string $plate,
        #[ORM\Column(name: 'driver_contact_id', type: 'guid', nullable: true)]
        private ?string $driverContactId,
        \DateTimeImmutable $createdAt,
    ) {
        $this->createdAt = $createdAt;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function plate(): string
    {
        return $this->plate;
    }

    public function driverContactId(): ?string
    {
        return $this->driverContactId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function withoutDriver(): void
    {
        $this->driverContactId = null;
    }
}
