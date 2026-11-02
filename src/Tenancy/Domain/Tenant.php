<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * An organisation using the installation. Its time zone decides when its days
 * begin for scheduled work; without one, the zone of the country of its first
 * active firm applies, see the tenant directory.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenant')]
class Tenant
{
    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    // A zone of the tz database, such as "Europe/Prague"; never an offset, which knows no summer time.
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $timezone = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'tenant_id')]
        private readonly TenantId $id,
        string $name,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('A tenant needs a name.');
        }
        $this->name = $name;
    }

    public function id(): TenantId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The zone stored for the tenant; null when it follows its firm's country.
     */
    public function timezone(): ?\DateTimeZone
    {
        return null === $this->timezone ? null : new \DateTimeZone($this->timezone);
    }

    /**
     * Null goes back to the zone of the first active firm's country.
     *
     * @throws \InvalidArgumentException when the zone is an offset or an abbreviation, not a zone of the tz database
     */
    public function changeTimezone(?\DateTimeZone $zone): void
    {
        if ($zone instanceof \DateTimeZone && !self::isNamed($zone)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a time zone of the tz database.', $zone->getName()));
        }
        $this->timezone = $zone?->getName();
    }

    public static function isNamed(\DateTimeZone $zone): bool
    {
        return \in_array($zone->getName(), \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true);
    }
}
