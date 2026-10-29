<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'tenant')]
class Tenant
{
    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

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
}
