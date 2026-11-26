<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Invoicing\Domain\PartyInvoicingDefault;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Domain\Id;

/**
 * The invoicing defaults of the parties, one row of Invoicing for each. A new
 * party gets its row with the standard defaults when it is persisted, so the
 * contact list filters every contact by them.
 */
#[AsEntityListener(event: Events::prePersist, method: 'partyPersisted', entity: Party::class)]
final readonly class DoctrinePartyInvoicingDefaults implements PartyInvoicingDefaults
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function of(string $partyId): InvoicingDefaults
    {
        return $this->ofParties([$partyId])[$partyId];
    }

    public function ofParties(array $partyIds): array
    {
        $found = [];
        $ids = Id::onlyUuids($partyIds);
        if ([] !== $ids) {
            /** @var list<PartyInvoicingDefault> $rows */
            $rows = $this->em->createQuery('SELECT d FROM '.PartyInvoicingDefault::class.' d WHERE d.id IN (:ids)')
                ->setParameter('ids', array_values(array_unique($ids)))
                ->getResult();
            foreach ($rows as $row) {
                $found[$row->partyId()] = $row->defaults();
            }
        }
        $defaults = [];
        foreach ($partyIds as $id) {
            $defaults[$id] = $found[$id] ?? $this->managed($id)?->defaults() ?? InvoicingDefaults::standard();
        }

        return $defaults;
    }

    public function change(string $partyId, InvoicingDefaults $defaults): void
    {
        $row = $this->stored($partyId);
        if ($row instanceof PartyInvoicingDefault) {
            $row->change($defaults);

            return;
        }
        $party = $this->em->find(Party::class, $partyId) ?? throw new \DomainException(\sprintf('There is no party %s.', $partyId));
        $this->em->persist(new PartyInvoicingDefault($party, $defaults));
    }

    public function partyPersisted(Party $party): void
    {
        if (!$this->managed($party->id()) instanceof PartyInvoicingDefault) {
            $this->em->persist(new PartyInvoicingDefault($party));
        }
    }

    /**
     * The row of the party, also one persisted in this request and not flushed yet.
     */
    private function stored(string $partyId): ?PartyInvoicingDefault
    {
        $managed = $this->managed($partyId);
        if ($managed instanceof PartyInvoicingDefault) {
            return $managed;
        }

        return Id::isValid($partyId) ? $this->em->find(PartyInvoicingDefault::class, $partyId) : null;
    }

    private function managed(string $partyId): ?PartyInvoicingDefault
    {
        $managed = $this->em->getUnitOfWork()->tryGetById($partyId, PartyInvoicingDefault::class);

        return $managed instanceof PartyInvoicingDefault ? $managed : null;
    }
}
