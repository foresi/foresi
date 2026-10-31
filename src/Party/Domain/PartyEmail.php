<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

use App\Audit\Domain\Audited;
use App\Audit\Domain\AuditExcluded;
use App\Shared\Domain\Id;
use App\Tenancy\Domain\TenantId;
use App\Tenancy\Domain\TenantOwned;
use Doctrine\ORM\Mapping as ORM;

/**
 * An e-mail address of a party and what it is used for.
 */
#[ORM\Entity]
#[ORM\Table(name: 'party_email')]
#[ORM\Index(name: 'idx_party_email_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_party_email_party', columns: ['party_id'])]
#[ORM\Index(name: 'idx_party_email_key', columns: ['tenant_id', 'address_key'])]
class PartyEmail implements TenantOwned, Audited
{
    private const int MAX_LENGTH = 254;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'tenant_id', type: 'tenant_id', nullable: false)]
    private ?TenantId $tenantId;

    #[ORM\Column(type: 'string', length: 254)]
    private string $address;

    // The comparison form of the address, so a party is found by its e-mail in SQL, through an index.
    // Derived from the address, so it is left out of the audit log.
    #[AuditExcluded]
    #[ORM\Column(name: 'address_key', type: 'string', length: 254, options: ['default' => ''])]
    private string $addressKey;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Party::class)]
        #[ORM\JoinColumn(name: 'party_id', nullable: false)]
        private readonly Party $party,
        string $address,
        #[ORM\Column(type: 'string', length: 16, enumType: EmailPurpose::class)]
        private EmailPurpose $purpose,
    ) {
        $this->id = Id::generate();
        $this->tenantId = $party->stampedTenantId();
        $this->address = $this->validate($address);
        $this->addressKey = self::key($this->address);
    }

    /** E-mail addresses are compared without regard to case. */
    public static function key(string $address): string
    {
        return mb_strtolower(trim($address));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function party(): Party
    {
        return $this->party;
    }

    public function address(): string
    {
        return $this->address;
    }

    public function purpose(): EmailPurpose
    {
        return $this->purpose;
    }

    public function change(string $address, EmailPurpose $purpose): void
    {
        $this->address = $this->validate($address);
        $this->addressKey = self::key($this->address);
        $this->purpose = $purpose;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw new \LogicException('The tenant is stamped on persist.');
    }

    private function validate(string $address): string
    {
        $address = trim($address);
        if (\strlen($address) > self::MAX_LENGTH || 1 !== preg_match('/^[^@\s]+@[^@\s.]+(\.[^@\s.]+)+$/', $address)) {
            throw new \DomainException('The e-mail address is not valid.');
        }

        return $address;
    }
}
