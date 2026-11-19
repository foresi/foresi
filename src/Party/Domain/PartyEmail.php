<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Domain;

use Doctrine\ORM\Mapping as ORM;
use Foresi\Audit\Domain\Audited;
use Foresi\Audit\Domain\AuditExcluded;
use Foresi\Audit\Domain\AuditRooted;
use Foresi\Shared\Domain\Id;

/**
 * An e-mail address of a party and what it is used for.
 */
#[ORM\Entity]
#[ORM\Table(name: 'party_email')]
#[ORM\Index(name: 'idx_party_email_party', columns: ['party_id'])]
#[ORM\Index(name: 'idx_party_email_key', columns: ['address_key'])]
class PartyEmail implements Audited, AuditRooted
{
    private const int MAX_LENGTH = 254;
    private const string PATTERN = '/^[^@\s]+@[^@\s.]+(\.[^@\s.]+)+$/';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

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
        $this->address = $this->validate($address);
        $this->addressKey = self::key($this->address);
    }

    /** E-mail addresses are compared without regard to case. */
    public static function key(string $address): string
    {
        return mb_strtolower(trim($address));
    }

    public static function isValid(string $address): bool
    {
        $address = trim($address);

        return \strlen($address) <= self::MAX_LENGTH && 1 === preg_match(self::PATTERN, $address);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function auditRoot(): object
    {
        return $this->party;
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

    private function validate(string $address): string
    {
        if (!self::isValid($address)) {
            throw new \DomainException('The e-mail address is not valid.');
        }

        return trim($address);
    }
}
