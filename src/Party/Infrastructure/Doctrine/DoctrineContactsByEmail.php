<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Application\PartyDetails;
use Foresi\Party\Application\Query\ContactRow;
use Foresi\Party\Contract\ContactsByEmail;
use Foresi\Party\Contract\PartySummary;
use Foresi\Party\Domain\ContactLink;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Infrastructure\Directory\PartySummaryFactory;

/**
 * Finds contacts by e-mail address through the address key of party_email (an index for an
 * address, a suffix match for a domain). It does not ask who is signed in: mail is received with
 * nobody signed in, and it only returns summaries (see the contract).
 */
final readonly class DoctrineContactsByEmail implements ContactsByEmail
{
    public function __construct(private EntityManagerInterface $em, private PartyDetails $details, private PartySummaryFactory $summaries)
    {
    }

    public function byAddress(string $email): array
    {
        $key = PartyEmail::key($email);
        if ('' === $key) {
            return [];
        }
        /** @var list<Party> $parties */
        $parties = $this->em->createQuery(
            'SELECT DISTINCT p FROM '.Party::class.' p JOIN '.PartyEmail::class.' e WITH e.party = p WHERE e.addressKey = :key AND p.archivedAt IS NULL ORDER BY p.displayName, p.id',
        )->setParameter('key', $key)->getResult();

        return array_map($this->summary(...), $parties);
    }

    public function byDomain(string $domain): array
    {
        $domain = ltrim(PartyEmail::key($domain), '@');
        if ('' === $domain) {
            return [];
        }
        // The domain is literal text: a "%", "_" or "\" in it must not act as a wildcard.
        $suffix = '%@'.addcslashes($domain, '%_\\');
        /** @var list<Party> $parties */
        $parties = $this->em->createQuery(
            'SELECT DISTINCT p FROM '.Party::class.' p JOIN '.PartyEmail::class." e WITH e.party = p WHERE e.addressKey LIKE :suffix ESCAPE '\\' AND p.kind <> :person AND p.archivedAt IS NULL ORDER BY p.displayName, p.id",
        )->setParameter('suffix', $suffix)->setParameter('person', PartyKind::Person)->getResult();

        return array_map($this->summary(...), $parties);
    }

    public function companiesOfPerson(string $personId): array
    {
        /** @var list<ContactLink> $links */
        $links = $this->em->createQuery('SELECT l FROM '.ContactLink::class.' l JOIN l.person person WHERE person.id = :person ORDER BY l.id')
            ->setParameter('person', $personId)->getResult();

        return array_map(fn (ContactLink $link): PartySummary => $this->summary($link->company()), $links);
    }

    private function summary(Party $party): PartySummary
    {
        $billing = $this->details->billingAddress($party) ?? ($this->details->addresses($party)[0] ?? null);
        $row = ContactRow::of($party, $billing?->address(), $this->details->generalEmail($party)?->address());

        return $this->summaries->of($row, $row->billingAddress, $party->phone(), $party->mobile());
    }
}
