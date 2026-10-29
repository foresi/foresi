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

    private function demoteOthers(PartyBankAccount $account): void
    {
        $party = $account->party();
        // A party that is not stored yet has no other accounts.
        if (!$this->em->contains($party) || null === $this->em->getUnitOfWork()->getSingleIdentifierValue($party)) {
            return;
        }

        /** @var list<PartyBankAccount> $others */
        $others = $this->em->createQuery('SELECT b FROM '.PartyBankAccount::class.' b WHERE b.party = :party AND b.primary = true')
            ->setParameter('party', $party)
            ->getResult();
        foreach ($others as $other) {
            if ($other !== $account) {
                $other->demote();
            }
        }
    }
}
