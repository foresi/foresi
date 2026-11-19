<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Party\Application\PartyBankAccounts;
use Foresi\Party\Domain\PartyBankAccount;

final readonly class DoctrinePartyBankAccounts implements PartyBankAccounts
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(PartyBankAccount $account): void
    {
        $this->em->wrapInTransaction(function () use ($account): void {
            if ($account->isPrimary()) {
                $this->demoteOthers($account);
            }
            $this->em->persist($account);
            $this->em->flush();
        });
    }

    public function makePrimary(PartyBankAccount $account): void
    {
        $this->em->wrapInTransaction(function () use ($account): void {
            $this->demoteOthers($account);
            $account->makePrimary();
            $this->em->flush();
        });
    }

    public function remove(PartyBankAccount $account): void
    {
        $this->em->remove($account);
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
        $uow = $this->em->getUnitOfWork();
        $demoted = false;

        // Accounts waiting to be inserted and accounts already loaded, whose state may differ from the database.
        $known = [...$uow->getScheduledEntityInsertions(), ...($uow->getIdentityMap()[PartyBankAccount::class] ?? [])];
        foreach ($known as $other) {
            if ($other instanceof PartyBankAccount && $other !== $account && $other->party() === $party && $other->isPrimary()) {
                $other->demote();
                $demoted = true;
            }
        }

        // A party that is not stored yet has no stored accounts.
        if ($this->em->contains($party) && !$uow->isScheduledForInsert($party)) {
            /** @var list<PartyBankAccount> $stored */
            $stored = $this->em->createQuery('SELECT b FROM '.PartyBankAccount::class.' b WHERE b.party = :party AND b.primaryFlag = true')
                ->setParameter('party', $party)
                ->getResult();
            foreach ($stored as $other) {
                if ($other !== $account) {
                    $other->demote();
                    $demoted = true;
                }
            }
        }

        // The unique index refuses two primaries within one flush whatever the order of the statements.
        if ($demoted) {
            $this->em->flush();
        }
    }
}
