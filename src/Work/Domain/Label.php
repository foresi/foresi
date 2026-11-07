<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

use App\Audit\Domain\Audited;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * A label of the organisation, shared by all its projects, so one filter finds
 * every task with it. Names are unique per tenant: the index refuses an exact
 * duplicate, and the application refuses one differing only in case, which
 * SQLite and PostgreSQL would compare differently.
 */
#[ORM\Entity]
#[ORM\Table(name: 'label')]
#[ORM\Index(name: 'idx_label_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_label_name', columns: ['tenant_id', 'name'])]
class Label implements TenantOwned, Audited
{
    public const int MAX_NAME_LENGTH = 60;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId = null;

    #[ORM\Column(type: 'string', length: self::MAX_NAME_LENGTH)]
    private string $name;

    public function __construct(
        string $name,
        #[ORM\Column(type: 'string', length: 16, enumType: LabelColour::class)]
        private LabelColour $colour,
    ) {
        $this->id = Id::generate();
        $this->rename($name);
    }

    public function rename(string $name): void
    {
        $this->name = Name::of($name, self::MAX_NAME_LENGTH, 'A label');
    }

    public function recolour(LabelColour $colour): void
    {
        $this->colour = $colour;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function colour(): LabelColour
    {
        return $this->colour;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }
}
