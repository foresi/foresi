<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Audit\Domain\AuditEntry;
use App\Party\Application\PartyDetails;
use App\Party\Application\PartyHistoryEntry;
use App\Party\Domain\AddressType;
use App\Party\Domain\ContactLink;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyFirmSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tenant-scoped through the Doctrine tenant filter.
 */
final readonly class DoctrinePartyDetails implements PartyDetails
{
    private const int HISTORY_LIMIT = 100;

    private const array SUBJECTS = [
        Party::class => 'party',
        PartyAddress::class => 'address',
        PartyEmail::class => 'email',
        PartyBankAccount::class => 'bank_account',
        ContactLink::class => 'contact',
        PartyFirmSettings::class => 'firm_settings',
    ];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function addresses(Party $party): array
    {
        /** @var list<PartyAddress> $addresses */
        $addresses = $this->em->createQuery('SELECT a FROM '.PartyAddress::class.' a WHERE a.party = :party ORDER BY a.id')->setParameter('party', $party)->getResult();
        usort($addresses, static fn (PartyAddress $a, PartyAddress $b): int => (AddressType::Billing === $b->type() ? 1 : 0) <=> (AddressType::Billing === $a->type() ? 1 : 0));

        return $addresses;
    }

    public function billingAddress(Party $party): ?PartyAddress
    {
        foreach ($this->addresses($party) as $address) {
            if (AddressType::Billing === $address->type()) {
                return $address;
            }
        }

        return null;
    }

    public function emails(Party $party): array
    {
        /** @var list<PartyEmail> $emails */
        $emails = $this->em->createQuery('SELECT e FROM '.PartyEmail::class.' e WHERE e.party = :party AND e.legalEntity IS NULL ORDER BY e.id')->setParameter('party', $party)->getResult();

        return $emails;
    }

    public function generalEmail(Party $party): ?PartyEmail
    {
        foreach ($this->emails($party) as $email) {
            if (EmailPurpose::General === $email->purpose()) {
                return $email;
            }
        }

        return null;
    }

    public function bankAccounts(Party $party): array
    {
        /** @var list<PartyBankAccount> $accounts */
        $accounts = $this->em->createQuery('SELECT b FROM '.PartyBankAccount::class.' b WHERE b.party = :party ORDER BY b.id')->setParameter('party', $party)->getResult();
        usort($accounts, static fn (PartyBankAccount $a, PartyBankAccount $b): int => (int) $b->isPrimary() <=> (int) $a->isPrimary());

        return $accounts;
    }

    public function contactLinks(Party $party): array
    {
        /** @var list<ContactLink> $links */
        $links = $this->em->createQuery('SELECT l FROM '.ContactLink::class.' l WHERE l.company = :party OR l.person = :party ORDER BY l.id')->setParameter('party', $party)->getResult();

        return $links;
    }

    public function history(Party $party): array
    {
        $entries = $this->auditEntries('a.rootClass = :class AND a.rootId IN (:ids)', Party::class, [$party->id()]);
        // The person's side of a contact link is read by the links the person has now.
        /** @var list<string> $links */
        $links = $this->em->createQuery('SELECT l.id FROM '.ContactLink::class.' l WHERE l.person = :party')->setParameter('party', $party)->getSingleColumnResult();
        if ([] !== $links) {
            $entries = $this->merged($entries, $this->auditEntries('a.entityClass = :class AND a.entityId IN (:ids)', ContactLink::class, $links));
        }

        return array_map(
            static fn (AuditEntry $entry): PartyHistoryEntry => new PartyHistoryEntry($entry->occurredAt(), self::SUBJECTS[$entry->entityClass()] ?? 'party', $entry->action()->value, array_keys($entry->changes())),
            $entries,
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return list<AuditEntry>
     */
    private function auditEntries(string $condition, string $class, array $ids): array
    {
        /** @var list<AuditEntry> $entries */
        $entries = $this->em->createQuery('SELECT a FROM '.AuditEntry::class.' a WHERE '.$condition.' ORDER BY a.occurredAt DESC, a.id DESC')
            ->setMaxResults(self::HISTORY_LIMIT)
            ->setParameter('class', $class)
            ->setParameter('ids', $ids)
            ->getResult();

        return $entries;
    }

    /**
     * Two queries instead of an OR, so each one can use its own index. An entry
     * both found counts once.
     *
     * @param list<AuditEntry> $first
     * @param list<AuditEntry> $second
     *
     * @return list<AuditEntry>
     */
    private function merged(array $first, array $second): array
    {
        $byId = [];
        foreach ([...$first, ...$second] as $entry) {
            $byId[$entry->id()] = $entry;
        }
        usort($byId, static fn (AuditEntry $a, AuditEntry $b): int => [$b->occurredAt(), $b->id()] <=> [$a->occurredAt(), $a->id()]);

        return \array_slice($byId, 0, self::HISTORY_LIMIT);
    }
}
