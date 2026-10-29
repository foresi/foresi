<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Party\Application\PartyBankAccounts;
use App\Party\Domain\PartyBankAccount;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePartyBankAccounts implements PartyBankAccounts
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(PartyBankAccount $account): void
    {
        if ($account->isPrimary()) {
            $this->demoteOthers($account);
        }

        $this->em->persist($account);
    }

    public function makePrimary(PartyBankAccount $account): void
    {
        $this->demoteOthers($account);
        $account->makePrimary();
    }

    /**
     * Demotes the party's other primary accounts, the stored ones first and
     * flushed: the unique index would refuse two primaries within one flush
     * whatever the order of the statements. Accounts still waiting to be
     * inserted are demoted in memory.
     */
    private function demoteOthers(PartyBankAccount $account): void
    {
        $party = $account->party();
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $pending) {
            if ($pending instanceof PartyBankAccount && $pending !== $account && $pending->party() === $party) {
                $pending->demote();
            }
        }

        // A party that is not stored yet has no stored accounts.
        if (!$this->em->contains($party) || null === $this->em->getUnitOfWork()->getSingleIdentifierValue($party)) {
            return;
        }

        /** @var list<PartyBankAccount> $others */
        $others = $this->em->createQuery('SELECT b FROM '.PartyBankAccount::class.' b WHERE b.party = :party AND b.primaryFlag = true')
            ->setParameter('party', $party)
            ->getResult();
        $demoted = false;
        foreach ($others as $other) {
            if ($other !== $account) {
                $other->demote();
                $demoted = true;
            }
        }
        if ($demoted) {
            $this->em->flush();
        }
    }
}
