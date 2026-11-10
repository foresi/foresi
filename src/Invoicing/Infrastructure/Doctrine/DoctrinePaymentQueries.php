<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\Query\PaymentAllocationRow;
use App\Invoicing\Application\Query\PaymentQueries;
use App\Invoicing\Application\Query\PaymentRow;
use App\Invoicing\Application\Query\PaymentSliceFilter;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentAllocation;
use App\Shared\Domain\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Every read goes through the ORM, so the tenant filter narrows the payments and
 * their allocations both.
 */
final readonly class DoctrinePaymentQueries implements PaymentQueries
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function after(PaymentSliceFilter $filter, ?string $afterId, int $limit): array
    {
        $where = ['1 = 1'];
        /** @var array<string, array{mixed, string|null}> $parameters */
        $parameters = [];
        if (!$filter->includeCancelled) {
            $where[] = 'p.cancelledAt IS NULL';
        }
        if (null !== $filter->firmId) {
            $where[] = 'IDENTITY(p.firm) = :firm';
            $parameters['firm'] = [$filter->firmId, null];
        }
        if (null !== $filter->invoiceId) {
            $where[] = 'p.id IN (SELECT IDENTITY(pa.payment) FROM '.PaymentAllocation::class.' pa WHERE IDENTITY(pa.invoice) = :invoice)';
            $parameters['invoice'] = [$filter->invoiceId, null];
        }
        if ($filter->direction instanceof \App\Invoicing\Domain\PaymentDirection) {
            $where[] = 'p.direction = :direction';
            $parameters['direction'] = [$filter->direction, null];
        }
        if ($filter->source instanceof \App\Invoicing\Domain\PaymentSource) {
            $where[] = 'p.source = :source';
            $parameters['source'] = [$filter->source, null];
        }
        if ($filter->method instanceof \App\Party\Domain\PaymentMethod) {
            $where[] = 'p.method = :method';
            $parameters['method'] = [$filter->method, null];
        }
        if ($filter->receivedFrom instanceof \DateTimeImmutable) {
            $where[] = 'p.receivedOn >= :from';
            $parameters['from'] = [$filter->receivedFrom, Types::DATE_IMMUTABLE];
        }
        if ($filter->receivedTo instanceof \DateTimeImmutable) {
            $where[] = 'p.receivedOn <= :to';
            $parameters['to'] = [$filter->receivedTo, Types::DATE_IMMUTABLE];
        }
        if (null !== $afterId) {
            $where[] = 'p.id < :afterId';
            $parameters['afterId'] = [$afterId, null];
        }

        $query = $this->em->createQuery('SELECT p FROM '.Payment::class.' p WHERE '.implode(' AND ', $where).' ORDER BY p.id DESC')->setMaxResults($limit);
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }

        /** @var list<Payment> $payments */
        $payments = $query->getResult();

        return $this->rows($payments);
    }

    public function find(string $id): ?PaymentRow
    {
        // A query, not find(): find() answers from the identity map without the tenant filter.
        $payment = $this->em->createQuery('SELECT p FROM '.Payment::class.' p WHERE p.id = :id')->setParameter('id', $id)->getOneOrNullResult();

        return $payment instanceof Payment ? $this->rows([$payment])[0] : null;
    }

    /**
     * @param list<Payment> $payments
     *
     * @return list<PaymentRow>
     */
    private function rows(array $payments): array
    {
        if ([] === $payments) {
            return [];
        }

        $allocations = [];
        /** @var list<PaymentAllocation> $all */
        $all = $this->em->createQuery('SELECT a, i FROM '.PaymentAllocation::class.' a JOIN a.invoice i WHERE a.payment IN (:payments) ORDER BY i.number, i.id')
            ->setParameter('payments', array_map(static fn (Payment $payment): string => $payment->id(), $payments))
            ->getResult();
        foreach ($all as $allocation) {
            $currency = $allocation->payment()->amount()->currency();
            $allocations[$allocation->payment()->id()][] = new PaymentAllocationRow(
                $allocation->invoice()->id(),
                $allocation->invoice()->number(),
                Money::ofMinor(abs($allocation->amount()->minor()), $currency),
                $allocation->settlement(),
            );
        }

        return array_map(static fn (Payment $payment): PaymentRow => new PaymentRow(
            $payment->id(),
            $payment->firm()->id(),
            $payment->firm()->name(),
            $payment->receivedOn(),
            $payment->amount(),
            $payment->direction(),
            $payment->method(),
            $payment->source(),
            $payment->note(),
            $payment->bankMovementId(),
            $payment->cancelledAt(),
            $payment->createdAt(),
            $allocations[$payment->id()] ?? [],
        ), $payments);
    }
}
