<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Party\Application\FirmBankAccounts;
use App\Party\Application\UnknownFirmBankAccount;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\LegalEntity;
use App\Tenancy\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Tenant-scoped through the Doctrine tenant filter.
 */
final readonly class DoctrineFirmBankAccounts implements FirmBankAccounts
{
    /** The statement that locks a movement's account too: plain SQL, so the tenant is in it. */
    private const string LOCK = 'UPDATE firm_bank_account SET position = position WHERE id = :id AND tenant_id = :tenant';

    public function __construct(private EntityManagerInterface $em, private TenantContext $context)
    {
    }

    public function of(LegalEntity $firm, bool $withArchived = false): array
    {
        /** @var list<FirmBankAccount> $accounts */
        $accounts = $this->em->createQuery('SELECT a FROM '.FirmBankAccount::class.' a WHERE a.firm = :firm'.($withArchived ? '' : ' AND a.archivedAt IS NULL').' ORDER BY a.position, a.id')
            ->setParameter('firm', $firm)
            ->getResult();
        // In PHP: databases disagree on where an ORDER BY puts the NULL of the other accounts. The sort is stable.
        usort($accounts, static fn (FirmBankAccount $a, FirmBankAccount $b): int => (int) $b->isPrimary() <=> (int) $a->isPrimary());

        return $accounts;
    }

    public function primaryOf(LegalEntity $firm): ?FirmBankAccount
    {
        $first = $this->of($firm)[0] ?? null;

        return true === $first?->isPrimary() ? $first : null;
    }

    public function ibansOf(LegalEntity $firm): array
    {
        return array_map(static fn (FirmBankAccount $account): string => $account->iban(), $this->of($firm));
    }

    public function get(string $id): FirmBankAccount
    {
        // A query, not find(): find() answers from the identity map without the tenant filter.
        $account = $this->em->createQuery('SELECT a FROM '.FirmBankAccount::class.' a WHERE a.id = :id')->setParameter('id', $id)->getOneOrNullResult();

        return $account instanceof FirmBankAccount ? $account : throw new UnknownFirmBankAccount();
    }

    public function lock(string $id): FirmBankAccount
    {
        $locked = $this->em->getConnection()->executeStatement(self::LOCK, ['id' => $id, 'tenant' => $this->context->current()->toString()]);
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

    public function ofIban(LegalEntity $firm, string $iban): ?FirmBankAccount
    {
        $account = $this->em->createQuery('SELECT a FROM '.FirmBankAccount::class.' a WHERE a.firm = :firm AND a.iban = :iban')
            ->setParameter('firm', $firm)
            ->setParameter('iban', $iban)
            ->getOneOrNullResult();

        return $account instanceof FirmBankAccount ? $account : null;
    }

    public function nextPosition(LegalEntity $firm): int
    {
        $last = $this->em->createQuery('SELECT MAX(a.position) FROM '.FirmBankAccount::class.' a WHERE a.firm = :firm')
            ->setParameter('firm', $firm)
            ->getSingleScalarResult();

        return null === $last ? 0 : (int) $last + 1;
    }

    public function add(FirmBankAccount $account): void
    {
        $this->em->persist($account);
    }

    public function makePrimary(FirmBankAccount $account): void
    {
        foreach ($this->of($account->firm()) as $other) {
            if ($other !== $account && $other->isPrimary()) {
                $other->markPrimary(false);
            }
        }
        // What the database holds: a primary demoted in memory, by archiving for one, is still primary there.
        /** @var list<string> $stored */
        $stored = $this->em->createQuery('SELECT a.id FROM '.FirmBankAccount::class.' a WHERE a.firm = :firm AND a.primaryFlag = true')
            ->setParameter('firm', $account->firm())
            ->getSingleColumnResult();
        if ([] !== array_diff($stored, [$account->id()])) {
            $this->em->flush();
        }
        $account->markPrimary(true);
    }
}
