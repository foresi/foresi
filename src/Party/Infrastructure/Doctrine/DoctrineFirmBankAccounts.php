<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Foresi\Party\Application\FirmBankAccounts;
use Foresi\Party\Application\UnknownFirmBankAccount;
use Foresi\Party\Domain\FirmBankAccount;

final readonly class DoctrineFirmBankAccounts implements FirmBankAccounts
{
    /** The statement that locks a movement's account too: plain SQL. */
    private const string LOCK = 'UPDATE firm_bank_account SET position = position WHERE id = :id';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function all(bool $withArchived = false): array
    {
        /** @var list<FirmBankAccount> $accounts */
        $accounts = $this->em->createQuery('SELECT a FROM '.FirmBankAccount::class.' a'.($withArchived ? '' : ' WHERE a.archivedAt IS NULL').' ORDER BY a.position, a.id')
            ->getResult();
        // In PHP: databases disagree on where an ORDER BY puts the NULL of the other accounts. The sort is stable.
        usort($accounts, static fn (FirmBankAccount $a, FirmBankAccount $b): int => (int) $b->isPrimary() <=> (int) $a->isPrimary());

        return $accounts;
    }

    public function primary(): ?FirmBankAccount
    {
        $first = $this->all()[0] ?? null;

        return true === $first?->isPrimary() ? $first : null;
    }

    public function ibans(): array
    {
        return array_map(static fn (FirmBankAccount $account): string => $account->iban(), $this->all());
    }

    public function get(string $id): FirmBankAccount
    {
        $account = $this->em->createQuery('SELECT a FROM '.FirmBankAccount::class.' a WHERE a.id = :id')->setParameter('id', $id)->getOneOrNullResult();

        return $account instanceof FirmBankAccount ? $account : throw new UnknownFirmBankAccount();
    }

    public function lock(string $id): FirmBankAccount
    {
        $locked = $this->em->getConnection()->executeStatement(self::LOCK, ['id' => $id]);
        if (1 !== $locked) {
            throw new UnknownFirmBankAccount();
        }
        // What this process remembers of the account may be older than the lock.
        $account = $this->em->createQuery('SELECT a FROM '.FirmBankAccount::class.' a WHERE a.id = :id')
            ->setParameter('id', $id)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $account instanceof FirmBankAccount ? $account : throw new UnknownFirmBankAccount();
    }

    public function ofIban(string $iban): ?FirmBankAccount
    {
        $account = $this->em->createQuery('SELECT a FROM '.FirmBankAccount::class.' a WHERE a.iban = :iban')
            ->setParameter('iban', $iban)
            ->getOneOrNullResult();

        return $account instanceof FirmBankAccount ? $account : null;
    }

    public function nextPosition(): int
    {
        $last = $this->em->createQuery('SELECT MAX(a.position) FROM '.FirmBankAccount::class.' a')
            ->getSingleScalarResult();

        return null === $last ? 0 : (int) $last + 1;
    }

    public function add(FirmBankAccount $account): void
    {
        $this->em->persist($account);
    }

    public function makePrimary(FirmBankAccount $account): void
    {
        foreach ($this->all() as $other) {
            if ($other !== $account && $other->isPrimary()) {
                $other->markPrimary(false);
            }
        }
        // What the database holds: a primary demoted in memory, by archiving for one, is still primary there.
        /** @var list<string> $stored */
        $stored = $this->em->createQuery('SELECT a.id FROM '.FirmBankAccount::class.' a WHERE a.primaryFlag = true')
            ->getSingleColumnResult();
        if ([] !== array_diff($stored, [$account->id()])) {
            $this->em->flush();
        }
        $account->markPrimary(true);
    }
}
