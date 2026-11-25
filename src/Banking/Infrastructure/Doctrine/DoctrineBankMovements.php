<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Foresi\Banking\Application\BankMovements;
use Foresi\Banking\Application\MovementFilter;
use Foresi\Banking\Application\MovementInput;
use Foresi\Banking\Application\MovementRow;
use Foresi\Banking\Application\UnknownBankMovement;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Domain\BankMovement;
use Foresi\Banking\Domain\MovementState;
use Foresi\Invoicing\Domain\Payment;
use Foresi\Invoicing\Domain\PaymentAllocation;
use Foresi\Party\Domain\FirmBankAccount;

/**
 * Reads go through the ORM; the two locks are plain statements. A page of the
 * screen takes one query for its movements and one for the invoice numbers of all of them.
 */
final readonly class DoctrineBankMovements implements BankMovements
{
    private const string LOCK = 'UPDATE bank_movement SET version = version WHERE id = :id';
    private const string LOCK_ACCOUNT = 'UPDATE firm_bank_account SET position = position WHERE id = :id';

    public function __construct(private EntityManagerInterface $em, private MovementConditions $conditions)
    {
    }

    public function get(string $id): BankMovement
    {
        // A query, not find(): find() answers from the identity map.
        $movement = $this->em->createQuery('SELECT m FROM '.BankMovement::class.' m WHERE m.id = :id')->setParameter('id', $id)->getOneOrNullResult();

        return $movement instanceof BankMovement ? $movement : throw new UnknownBankMovement();
    }

    public function lock(string $id): BankMovement
    {
        $locked = $this->em->getConnection()->executeStatement(self::LOCK, ['id' => $id]);
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
        $this->em->getConnection()->executeStatement(self::LOCK_ACCOUNT, ['id' => $accountId]);
    }

    public function add(BankMovement $movement): void
    {
        $this->em->persist($movement);
    }

    public function fingerprintTakenByOther(string $fingerprint, string $sourceId, ?string $inboundMessageId, ?string $statementId = null): bool
    {
        $dql = 'SELECT COUNT(m.id) FROM '.BankMovement::class.' m WHERE m.fingerprint = :fingerprint AND m.sourceId = :source';
        $parameters = ['fingerprint' => $fingerprint, 'source' => $sourceId];
        if (null !== $inboundMessageId) {
            $dql .= ' AND (m.inboundMessageId IS NULL OR m.inboundMessageId <> :message)';
            $parameters['message'] = $inboundMessageId;
        } elseif (null !== $statementId) {
            $dql .= ' AND (m.statementId IS NULL OR m.statementId <> :statement)';
            $parameters['statement'] = $statementId;
        }

        return 0 < (int) $this->em->createQuery($dql)->setParameters($parameters)->getSingleScalarResult();
    }

    public function referenceKnown(string $accountId, string $sourceId, string $reference): bool
    {
        $found = $this->em->createQuery('SELECT m.id FROM '.BankMovement::class.' m WHERE m.account = :account AND ((m.sourceId = :source AND m.bankReference = :reference) OR (m.corroboratedSourceId = :source AND m.corroboratedReference = :reference))')
            ->setParameters(['account' => $accountId, 'source' => $sourceId, 'reference' => $reference])
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return null !== $found;
    }

    public function corroborationCandidates(MovementInput $input): array
    {
        $symbol = null === $input->variableSymbol ? 'm.variableSymbol IS NULL' : 'm.variableSymbol = :symbol';
        $query = $this->em->createQuery('SELECT m FROM '.BankMovement::class.' m WHERE m.account = :account AND m.sourceId <> :source AND m.corroboratedAt IS NULL AND m.state <> :duplicate'
            .' AND m.direction = :direction AND m.amountMinor = :minor AND m.currency = :currency AND m.bookedOn = :booked AND '.$symbol.' ORDER BY m.id')
            ->setParameters([
                'account' => $input->accountId,
                'source' => $input->sourceId,
                'duplicate' => MovementState::PossibleDuplicate,
                'direction' => $input->direction,
                'minor' => $input->amount->minor(),
                'currency' => $input->amount->currency(),
                'booked' => $input->bookedOn->format('Y-m-d'),
            ]);
        if (null !== $input->variableSymbol) {
            $query->setParameter('symbol', $input->variableSymbol);
        }
        /** @var list<BankMovement> $movements */
        $movements = $query->getResult();

        return array_values(array_filter($movements, static fn (BankMovement $movement): bool => self::sameCounterAccount($movement, $input)));
    }

    /**
     * A counter-account stated on both sides by IBAN or by local number is the same;
     * one stated on one side only, or in two ways that cannot be compared, does not
     * tell the two apart.
     */
    private static function sameCounterAccount(BankMovement $movement, MovementInput $input): bool
    {
        if (null !== $movement->counterIban() && null !== $input->counterIban) {
            return self::compact($movement->counterIban()) === self::compact($input->counterIban);
        }
        if (null !== $movement->counterAccount() && null !== $input->counterAccount) {
            return self::compact($movement->counterAccount()) === self::compact($input->counterAccount);
        }

        return true;
    }

    private static function compact(?string $account): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', (string) $account));
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
        /** @var list<array{id: string, label: string, iban: string}> $rows */
        $rows = $this->em->createQuery('SELECT a.id AS id, a.label AS label, a.iban AS iban FROM '.FirmBankAccount::class.' a ORDER BY a.position, a.id')
            ->getArrayResult();
        $accounts = [];
        foreach ($rows as $row) {
            $accounts[$row['id']] = ['label' => $row['label'], 'iban' => $row['iban']];
        }

        return $accounts;
    }

    public function count(MovementFilter $filter): int
    {
        if ($filter->mail()) {
            return 0;
        }
        [$where, $parameters] = $this->narrowed($filter);

        return (int) MovementConditions::bind($this->em->createQuery('SELECT COUNT(m.id) FROM '.BankMovement::class.' m WHERE '.$where), $parameters)->getSingleScalarResult();
    }

    public function counts(string $text, array $views): array
    {
        if ([] === $views) {
            return [];
        }
        [$where, $parameters] = $this->searched($text);
        $sums = [];
        foreach (array_values($views) as $index => $view) {
            [$condition, $bound] = $this->conditions->movements($view, 'c'.$index);
            $sums[] = 'SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END)';
            $parameters += $bound;
        }
        /** @var array<array-key, numeric-string|int|null> $results */
        $results = MovementConditions::bind($this->em->createQuery('SELECT '.implode(', ', $sums).' FROM '.BankMovement::class.' m WHERE '.$where), $parameters)->getSingleResult(Query::HYDRATE_ARRAY);

        return array_combine(array_map(strval(...), array_keys($views)), array_map(static fn (int|string|null $value): int => (int) $value, array_values($results)));
    }

    public function rows(MovementFilter $filter, int $offset, int $limit): array
    {
        if ($filter->mail()) {
            return [];
        }
        [$where, $parameters] = $this->narrowed($filter);
        $query = MovementConditions::bind($this->em->createQuery('SELECT m, a FROM '.BankMovement::class.' m JOIN m.account a WHERE '.$where.' ORDER BY m.bookedOn DESC, m.id DESC'), $parameters);
        /** @var list<BankMovement> $movements */
        $movements = $query->setFirstResult(max(0, $offset))->setMaxResults(max(1, $limit))->getResult();
        $invoices = $this->invoicesOf(array_values(array_filter(array_map(static fn (BankMovement $movement): ?string => $movement->paymentId(), $movements), \is_string(...))));

        return array_map(static fn (BankMovement $movement): MovementRow => new MovementRow(
            $movement->id(),
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
     * The view's movements within the text; one movement asked for by its id, whichever
     * view it is in.
     *
     * @return array{string, array<string, array{mixed, string|ArrayParameterType|null}>}
     */
    private function narrowed(MovementFilter $filter): array
    {
        if (null !== $filter->movementId) {
            return ['m.id = :movement', ['movement' => [$filter->movementId, null]]];
        }
        [$where, $parameters] = $this->searched($filter->text);
        [$condition, $bound] = $this->conditions->movements($filter->view, 'v');

        return [$where.' AND '.$condition, $parameters + $bound];
    }

    /**
     * The movements the text finds by partner, message, symbol, account or amount.
     *
     * @return array{string, array<string, array{mixed, string|ArrayParameterType|null}>}
     */
    private function searched(string $text): array
    {
        $text = trim($text);
        if ('' === $text) {
            return ['1 = 1', []];
        }
        $found = ["LOWER(m.counterName) LIKE :text ESCAPE '!'", "LOWER(m.message) LIKE :text ESCAPE '!'", "m.variableSymbol LIKE :text ESCAPE '!'", "m.counterAccount LIKE :text ESCAPE '!'"];
        $parameters = ['text' => ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($text)).'%', null]];
        $amount = str_replace([' ', "\u{a0}", "\u{202f}", ','], ['', '', '', '.'], $text);
        if (1 === preg_match('/^\d{1,13}(?:\.\d{1,2})?$/D', $amount)) {
            [$whole, $decimals] = explode('.', $amount.'.', 3);
            $found[] = 'm.amountMinor = :minor';
            $parameters['minor'] = [(int) $whole * 100 + (int) str_pad($decimals, 2, '0'), null];
        }

        return ['('.implode(' OR ', $found).')', $parameters];
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
