<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Doctrine;

use App\Banking\Application\BankMovements;
use App\Banking\Application\MovementFilter;
use App\Banking\Application\MovementRow;
use App\Banking\Application\UnknownBankMovement;
use App\Banking\Domain\BankMovement;
use App\Banking\Domain\MovementDirection;
use App\Banking\Domain\MovementState;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentAllocation;
use App\Party\Domain\FirmBankAccount;
use App\Tenancy\Application\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * Reads go through the ORM, so the tenant filter narrows them; the two locks are
 * plain statements that name the tenant themselves. A page of the screen takes
 * one query for its movements and one for the invoice numbers of all of them.
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

    public function unmatchedIncoming(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(m.id) FROM '.BankMovement::class.' m WHERE m.state = :state AND m.direction = :direction')
            ->setParameter('state', MovementState::Unmatched)
            ->setParameter('direction', MovementDirection::Incoming)
            ->getSingleScalarResult();
    }

    public function accounts(): array
    {
        /** @var list<array{id: string, label: string, iban: string, firmId: string, firmName: string}> $rows */
        $rows = $this->em->createQuery('SELECT a.id AS id, a.label AS label, a.iban AS iban, f.id AS firmId, f.name AS firmName FROM '.FirmBankAccount::class.' a JOIN a.firm f ORDER BY f.name, f.id, a.position, a.id')
            ->getArrayResult();
        $accounts = [];
        foreach ($rows as $row) {
            $accounts[$row['id']] = ['firmId' => $row['firmId'], 'firmName' => $row['firmName'], 'label' => $row['label'], 'iban' => $row['iban']];
        }

        return $accounts;
    }

    public function count(MovementFilter $filter): int
    {
        if (MovementFilter::MESSAGES === $filter->tab) {
            return 0;
        }
        [$where, $parameters] = $this->narrowed($filter);
        $query = $this->em->createQuery('SELECT COUNT(m.id) FROM '.BankMovement::class.' m WHERE '.$where);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        return (int) $query->getSingleScalarResult();
    }

    public function rows(MovementFilter $filter, int $offset, int $limit): array
    {
        if (MovementFilter::MESSAGES === $filter->tab) {
            return [];
        }
        [$where, $parameters] = $this->narrowed($filter);
        $query = $this->em->createQuery('SELECT m, a, f FROM '.BankMovement::class.' m JOIN m.account a JOIN m.firm f WHERE '.$where.' ORDER BY m.bookedOn DESC, m.id DESC');
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }
        /** @var list<BankMovement> $movements */
        $movements = $query->setFirstResult(max(0, $offset))->setMaxResults(max(1, $limit))->getResult();
        $invoices = $this->invoicesOf(array_values(array_filter(array_map(static fn (BankMovement $movement): ?string => $movement->paymentId(), $movements), \is_string(...))));

        return array_map(static fn (BankMovement $movement): MovementRow => new MovementRow(
            $movement->id(),
            $movement->firm()->id(),
            $movement->firm()->name(),
            $movement->account()->id(),
            $movement->account()->label(),
            $movement->account()->iban(),
            $movement->direction(),
            $movement->amount(),
            $movement->bookedOn(),
            $movement->counterName(),
            $movement->counterAccount() ?? $movement->counterIban(),
            $movement->variableSymbol(),
            $movement->message(),
            $movement->state(),
            $movement->unmatchedReason(),
            $movement->trust(),
            $movement->inboundMessageId(),
            $invoices[$movement->paymentId() ?? ''] ?? [],
        ), $movements);
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function narrowed(MovementFilter $filter): array
    {
        $where = ['1 = 1'];
        $parameters = [];
        if (null !== $filter->movementId) {
            // One movement asked for by its id, whichever way it went.
            $where[] = 'm.id = :movement';
            $parameters['movement'] = $filter->movementId;
        } elseif (MovementFilter::OUTGOING === $filter->tab) {
            $where[] = 'm.direction = :direction';
            $parameters['direction'] = MovementDirection::Outgoing;
        } else {
            $where[] = 'm.direction = :direction';
            $parameters['direction'] = MovementDirection::Incoming;
            if (MovementFilter::UNMATCHED === $filter->tab) {
                $where[] = 'm.state = :state';
                $parameters['state'] = MovementState::Unmatched;
            }
        }
        if (null !== $filter->firmId) {
            $where[] = 'm.firm = :firm';
            $parameters['firm'] = $filter->firmId;
        }
        if (null !== $filter->accountId) {
            $where[] = 'm.account = :account';
            $parameters['account'] = $filter->accountId;
        }
        $text = trim($filter->text);
        if ('' !== $text) {
            $found = ["LOWER(m.counterName) LIKE :text ESCAPE '!'", "LOWER(m.message) LIKE :text ESCAPE '!'", "m.variableSymbol LIKE :text ESCAPE '!'", "m.counterAccount LIKE :text ESCAPE '!'"];
            $parameters['text'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($text)).'%';
            $amount = str_replace([' ', "\u{a0}", "\u{202f}", ','], ['', '', '', '.'], $text);
            if (1 === preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $amount)) {
                [$whole, $decimals] = explode('.', $amount.'.', 3);
                $found[] = 'm.amountMinor = :minor';
                $parameters['minor'] = (int) $whole * 100 + (int) str_pad($decimals, 2, '0');
            }
            $where[] = '('.implode(' OR ', $found).')';
        }

        return [implode(' AND ', $where), $parameters];
    }

    /**
     * The numbers of the invoices each payment pays, in one query for all of them.
     *
     * @param list<string> $paymentIds
     *
     * @return array<string, array<string, string>> number by invoice id, by payment id
     */
    private function invoicesOf(array $paymentIds): array
    {
        if ([] === $paymentIds) {
            return [];
        }
        /** @var list<array{payment: string, id: string, number: string}> $rows */
        $rows = $this->em->createQuery('SELECT IDENTITY(a.payment) AS payment, i.id AS id, i.number AS number FROM '.PaymentAllocation::class.' a JOIN a.invoice i WHERE a.payment IN (:payments) ORDER BY i.number, i.id')
            ->setParameter('payments', $paymentIds)
            ->getArrayResult();
        $invoices = [];
        foreach ($rows as $row) {
            $invoices[$row['payment']][$row['id']] = $row['number'];
        }

        return $invoices;
    }
}
