<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Doctrine;

use App\Banking\Application\BankMovements;
use App\Banking\Application\UnknownBankMovement;
use App\Banking\Domain\BankMovement;
use App\Invoicing\Domain\Payment;
use App\Tenancy\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Reads go through the ORM, so the tenant filter narrows them; the two locks are
 * plain statements that name the tenant themselves.
 */
final readonly class DoctrineBankMovements implements BankMovements
{
    private const string LOCK = 'UPDATE bank_movement SET version = version WHERE id = :id AND tenant_id = :tenant';
    private const string LOCK_ACCOUNT = 'UPDATE firm_bank_account SET position = position WHERE id = :id AND tenant_id = :tenant';

    public function __construct(private EntityManagerInterface $em, private TenantContext $context)
    {
    }

    public function get(string $id): BankMovement
    {
        // A query, not find(): find() answers from the identity map without the tenant filter.
        $movement = $this->em->createQuery('SELECT m FROM '.BankMovement::class.' m WHERE m.id = :id')->setParameter('id', $id)->getOneOrNullResult();

        return $movement instanceof BankMovement ? $movement : throw new UnknownBankMovement();
    }

    public function lock(string $id): BankMovement
    {
        $locked = $this->em->getConnection()->executeStatement(self::LOCK, ['id' => $id, 'tenant' => $this->context->current()->toString()]);
        if (1 !== $locked) {
            throw new UnknownBankMovement();
        }
        // What this process remembers of the movement may be older than the lock.
        $movement = $this->em->createQuery('SELECT m FROM '.BankMovement::class.' m WHERE m.id = :id')
            ->setParameter('id', $id)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $movement instanceof BankMovement ? $movement : throw new UnknownBankMovement();
    }

    public function lockAccount(string $accountId): void
    {
        $this->em->getConnection()->executeStatement(self::LOCK_ACCOUNT, ['id' => $accountId, 'tenant' => $this->context->current()->toString()]);
    }

    public function add(BankMovement $movement): void
    {
        $this->em->persist($movement);
    }

    public function fingerprintTakenByOther(string $fingerprint, ?string $inboundMessageId): bool
    {
        $query = null === $inboundMessageId
            ? $this->em->createQuery('SELECT COUNT(m.id) FROM '.BankMovement::class.' m WHERE m.fingerprint = :fingerprint')
            : $this->em->createQuery('SELECT COUNT(m.id) FROM '.BankMovement::class.' m WHERE m.fingerprint = :fingerprint AND (m.inboundMessageId IS NULL OR m.inboundMessageId <> :message)')
                ->setParameter('message', $inboundMessageId);

        return 0 < (int) $query->setParameter('fingerprint', $fingerprint)->getSingleScalarResult();
    }

    public function hasLivePayment(string $movementId): bool
    {
        $count = $this->em->createQuery('SELECT COUNT(p.id) FROM '.Payment::class.' p WHERE p.bankMovementId = :movement AND p.cancelledAt IS NULL')
            ->setParameter('movement', $movementId)
            ->getSingleScalarResult();

        return 0 < (int) $count;
    }

    public function anyOnAccount(string $accountId): bool
    {
        $found = $this->em->createQuery('SELECT m.id FROM '.BankMovement::class.' m JOIN m.account a WHERE m.account = :account AND m.currency = a.currency')
            ->setParameter('account', $accountId)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return null !== $found;
    }
}
