<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Purchasing\Application\Query\ReceivedDocumentFilter;
use Foresi\Purchasing\Application\Query\ReceivedDocumentIdFilter;
use Foresi\Purchasing\Application\Query\ReceivedDocumentQueries;
use Foresi\Purchasing\Application\Query\ReceivedDocumentRow;
use Foresi\Purchasing\Application\Query\ReceivedDocumentSliceFilter;
use Foresi\Purchasing\Application\View\ReceivedDocumentLists;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\ReceivedDocumentPayment;
use Foresi\Purchasing\Domain\ReceivedDocumentState;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\DateRange;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Infrastructure\View\DqlConditions;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The lists of received documents in DQL over scalar columns, so a page loads
 * no document. A view's fields compile to the columns the sheet filtered by, and
 * the state and the trash to the SQL the tabs counted with (spec D3, D12).
 *
 * @phpstan-type Parameters array<string, array{mixed, string|ArrayParameterType|null}>
 */
final readonly class DoctrineReceivedDocumentQueries implements ReceivedDocumentQueries
{
    private const string COLUMNS = 'd.id, d.state, d.kind, d.treatment, d.evidenceNumber, d.supplier.name AS supplierName, d.supplierNumber, '
        .'d.issueDate, d.taxPointDate, d.receivedDate, d.currency, d.totalMinor, d.vatCzkMinor, d.createdAt, d.deletedAt, d.deletedBy, d.deletedReason, IDENTITY(d.supplierParty) AS supplierId';
    /** The fields of a view stored in a column of their own. */
    private const array VIEW_COLUMNS = [
        ReceivedDocumentLists::STATE => 'd.state',
        ReceivedDocumentLists::SUPPLIER => 'IDENTITY(d.supplierParty)',
        ReceivedDocumentLists::KIND => 'd.kind',
        ReceivedDocumentLists::TREATMENT => 'd.treatment',
    ];
    /** A document from abroad belongs to the month of its issue date (ReceivedDocumentFilter::byIssueDate). */
    private const string FROM_ABROAD = '(d.kind = :abroadKind OR d.treatment IN (:abroadTreatments))';

    /**
     * @param iterable<ReceivedDocumentIdFilter> $idFilters
     */
    public function __construct(
        private ReceivedDocumentLists $receivedLists,
        private EntityManagerInterface $em,
        private InstanceClock $clock,
        #[AutowireIterator(ReceivedDocumentIdFilter::ID_FILTER_TAG)]
        private iterable $idFilters = [],
    ) {
    }

    public function counts(ReceivedDocumentFilter $filter): array
    {
        [$where, $parameters] = $this->narrowed($filter);
        // One query counts the views the list shows a count for, and the one it lists (spec D9).
        $sums = [];
        foreach ([...array_values($filter->counted), $filter->view] as $index => $view) {
            [$condition, $bound] = $this->inView($view, 'c'.$index);
            $sums[] = 'SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END)';
            $parameters += $bound;
        }
        $query = $this->em->createQuery('SELECT '.implode(', ', $sums).' FROM '.ReceivedDocument::class.' d WHERE '.$where);
        $this->bind($query, $parameters);
        /** @var list<numeric-string|int|null> $results */
        $results = array_values((array) $query->getSingleResult(Query::HYDRATE_ARRAY));
        $values = array_map(static fn (int|string|null $value): int => (int) $value, $results);
        $total = (int) array_pop($values);

        return [$total, array_combine(array_map(strval(...), array_keys($filter->counted)), $values)];
    }

    public function rows(ReceivedDocumentFilter $filter, int $offset, int $limit): array
    {
        [$where, $parameters] = $this->narrowed($filter);
        [$condition, $bound] = $this->inView($filter->view, 'l');
        $where .= ' AND '.$condition;
        $parameters += $bound;
        $order = match ($filter->tab) {
            ReceivedDocumentFilter::DRAFTS => 'd.createdAt DESC, d.id DESC',
            ReceivedDocumentFilter::TRASH => 'd.deletedAt DESC, d.id DESC',
            default => 'd.receivedDate DESC, d.evidenceNumber DESC, d.id DESC',
        };
        $query = $this->em->createQuery('SELECT '.self::COLUMNS.' FROM '.ReceivedDocument::class.' d WHERE '.$where.' ORDER BY '.$order);
        $this->bind($query, $parameters);

        return $this->rowsOf($query->setFirstResult(max(0, $offset))->setMaxResults(max(1, $limit))->getArrayResult());
    }

    public function drafts(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(d.id) FROM '.ReceivedDocument::class.' d WHERE d.state = :draft AND d.deletedAt IS NULL')
            ->setParameter('draft', ReceivedDocumentState::Draft->value)
            ->getSingleScalarResult();
    }

    public function months(): array
    {
        $query = $this->em->createQuery('SELECT DISTINCT d.kind, d.treatment, d.issueDate, d.taxPointDate FROM '.ReceivedDocument::class.' d');
        $months = [];
        /** @var array{kind: ReceivedDocumentKind|string, treatment: PurchaseTaxTreatment|string, issueDate: ?\DateTimeImmutable, taxPointDate: ?\DateTimeImmutable} $row */
        foreach ($query->getArrayResult() as $row) {
            $day = ReceivedDocumentFilter::monthDay($this->kind($row['kind']), $this->treatment($row['treatment']), $row['issueDate'], $row['taxPointDate']);
            if ($day instanceof \DateTimeImmutable) {
                $months[$day->format('Y-m')] = true;
            }
        }
        $months = array_keys($months);
        rsort($months);

        return $months;
    }

    public function correctable(?string $exceptId, int $limit): array
    {
        $query = $this->em->createQuery('SELECT '.self::COLUMNS.' FROM '.ReceivedDocument::class.' d WHERE d.state = :recorded AND d.deletedAt IS NULL AND d.kind <> :creditNote'
            .(null === $exceptId ? '' : ' AND d.id <> :except').' ORDER BY d.receivedDate DESC, d.evidenceNumber DESC, d.id DESC')
            ->setParameter('recorded', ReceivedDocumentState::Recorded->value)
            ->setParameter('creditNote', ReceivedDocumentKind::CreditNote->value);
        if (null !== $exceptId) {
            $query->setParameter('except', $exceptId);
        }

        return $this->rowsOf($query->setMaxResults(max(1, $limit))->getArrayResult());
    }

    /**
     * What the month and the text leave, before any view: the tabs count within this.
     *
     * @return array{string, Parameters}
     */
    private function narrowed(ReceivedDocumentFilter $filter): array
    {
        $where = '1 = 1';
        $parameters = [];
        $month = ReceivedDocumentFilter::monthValue($filter->month);
        if (null !== $month) {
            $range = DateRange::of($month, $this->clock->today());
            $where .= ' AND (('.self::FROM_ABROAD.' AND d.issueDate >= :monthStart AND d.issueDate < :monthEnd)'
                .' OR (NOT '.self::FROM_ABROAD.' AND ((d.taxPointDate >= :monthStart AND d.taxPointDate < :monthEnd) OR (d.taxPointDate IS NULL AND d.issueDate >= :monthStart AND d.issueDate < :monthEnd))))';
            $parameters['monthStart'] = [$range->start, Types::DATE_IMMUTABLE];
            $parameters['monthEnd'] = [$range->end, Types::DATE_IMMUTABLE];
            $parameters['abroadKind'] = [ReceivedDocumentKind::ForeignInvoice->value, null];
            $parameters['abroadTreatments'] = [[PurchaseTaxTreatment::EuService->value, PurchaseTaxTreatment::NonEuService->value], ArrayParameterType::STRING];
        }
        $text = trim($filter->text);
        if ('' !== $text) {
            $where .= " AND (LOWER(d.supplier.name) LIKE :text ESCAPE '!' OR LOWER(d.supplierNumber) LIKE :text ESCAPE '!' OR LOWER(d.evidenceNumber) LIKE :text ESCAPE '!')";
            $parameters['text'] = ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($text)).'%', null];
        }

        return [$where, $parameters];
    }

    /**
     * The documents a view holds, out of the trash unless it asks for it; each view
     * under its own prefix, so one count query holds many side by side.
     *
     * @param string $trash the trash condition of a view that holds none
     *
     * @return array{string, Parameters}
     */
    private function inView(ViewFilter $view, string $prefix, string $trash = 'd.deletedAt IS NULL'): array
    {
        $derived = function (Condition $condition, string $name): array {
            if (ReceivedDocumentLists::TRASHED === $condition->field) {
                return [DqlConditions::derivedBoolean($condition, 'd.deletedAt IS NOT NULL'), []];
            }
            foreach ($this->idFilters as $filter) {
                if ($filter->field() === $condition->field) {
                    $ids = $filter->documentIds();

                    return [DqlConditions::derivedBoolean($condition, [] === $ids ? '1 = 0' : 'd.id IN (:'.$name.')'), [] === $ids ? [] : [$name => [$ids, ArrayParameterType::STRING]]];
                }
            }

            throw new \LogicException(\sprintf('The field "%s" of the received documents has no SQL.', $condition->field));
        };
        [$condition, $parameters] = new DqlConditions($this->receivedLists->list(), self::VIEW_COLUMNS, $derived(...))->compile($view, $this->clock->today(...), $prefix);

        return [([] === $view->on(ReceivedDocumentLists::TRASHED) ? $trash.' AND ' : '').$condition, $parameters];
    }

    public function slice(ReceivedDocumentSliceFilter $filter, ?string $afterId, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('A slice holds at least one document.');
        }
        if (null !== $afterId && !Id::isValid($afterId)) {
            throw new \InvalidArgumentException('An id is not valid.');
        }
        $conditions = [$filter->trashed ? 'd.deletedAt IS NOT NULL' : 'd.deletedAt IS NULL'];
        $parameters = [];
        if ($filter->view instanceof ViewFilter) {
            // A condition of the view on the trash decides instead of $trashed.
            [$conditions[0], $parameters] = $this->inView($filter->view, 'v', $conditions[0]);
        }
        if (null !== $filter->supplierId) {
            if (!Id::isValid($filter->supplierId)) {
                return [[], false];
            }
            $conditions[] = 'd.supplierParty = :supplier';
            $parameters['supplier'] = [$filter->supplierId, null];
        }
        if ($filter->state instanceof ReceivedDocumentState) {
            $conditions[] = 'd.state = :state';
            $parameters['state'] = [$filter->state->value, null];
        }
        if ($filter->unpaid) {
            // Recorded, with a total, and its payments short of it (PaymentBalance).
            $conditions[] = 'd.state = :recorded AND d.totalMinor IS NOT NULL AND d.totalMinor <> 0'
                .' AND ABS(d.totalMinor) > (SELECT COALESCE(SUM(p.amountMinor), 0) FROM '.ReceivedDocumentPayment::class.' p WHERE p.document = d)';
            $parameters['recorded'] = [ReceivedDocumentState::Recorded->value, null];
        }
        if ($filter->dueBefore instanceof \DateTimeImmutable) {
            $conditions[] = 'd.dueDate < :dueBefore';
            $parameters['dueBefore'] = [$filter->dueBefore, Types::DATE_IMMUTABLE];
        }
        if ($filter->dayFrom instanceof \DateTimeImmutable) {
            $conditions[] = 'COALESCE(d.taxPointDate, d.issueDate, d.receivedDate) >= :dayFrom';
            $parameters['dayFrom'] = [$filter->dayFrom, Types::DATE_IMMUTABLE];
        }
        if ($filter->dayTo instanceof \DateTimeImmutable) {
            $conditions[] = 'COALESCE(d.taxPointDate, d.issueDate, d.receivedDate) <= :dayTo';
            $parameters['dayTo'] = [$filter->dayTo, Types::DATE_IMMUTABLE];
        }
        if (null !== $afterId) {
            $conditions[] = 'd.id < :after';
            $parameters['after'] = [$afterId, null];
        }
        $query = $this->em->createQuery('SELECT d FROM '.ReceivedDocument::class.' d WHERE '.implode(' AND ', $conditions).' ORDER BY d.id DESC');
        $this->bind($query, $parameters);
        // One more than asked tells whether another slice follows.
        /** @var list<ReceivedDocument> $documents */
        $documents = $query->setMaxResults($limit + 1)->getResult();

        return [\array_slice($documents, 0, $limit), \count($documents) > $limit];
    }

    /**
     * @param Query<mixed, mixed> $query
     * @param Parameters          $parameters
     */
    private function bind(Query $query, array $parameters): void
    {
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }
    }

    /**
     * @param array<mixed> $result
     *
     * @return list<ReceivedDocumentRow>
     */
    private function rowsOf(array $result): array
    {
        $rows = [];
        /** @var array{id: string, state: ReceivedDocumentState|string, kind: ReceivedDocumentKind|string, treatment: PurchaseTaxTreatment|string, evidenceNumber: ?string, supplierName: string, supplierNumber: string, issueDate: ?\DateTimeImmutable, taxPointDate: ?\DateTimeImmutable, receivedDate: ?\DateTimeImmutable, currency: Currency|string, totalMinor: int|string|null, vatCzkMinor: int|string|null, createdAt: \DateTimeImmutable, deletedAt: ?\DateTimeImmutable, deletedBy: ?string, deletedReason: ?string, supplierId: ?string} $row */
        foreach ($result as $row) {
            $currency = $row['currency'] instanceof Currency ? $row['currency'] : Currency::from($row['currency']);
            $rows[] = new ReceivedDocumentRow(
                $row['id'],
                $row['state'] instanceof ReceivedDocumentState ? $row['state'] : ReceivedDocumentState::from($row['state']),
                $this->kind($row['kind']),
                $this->treatment($row['treatment']),
                $row['evidenceNumber'],
                $row['supplierName'],
                $row['supplierNumber'],
                $row['issueDate'],
                $row['taxPointDate'],
                $row['receivedDate'],
                null === $row['totalMinor'] ? null : Money::ofMinor((int) $row['totalMinor'], $currency),
                null === $row['vatCzkMinor'] ? null : Money::ofMinor((int) $row['vatCzkMinor'], Currency::CZK),
                $row['createdAt'],
                $row['deletedAt'],
                $row['deletedBy'],
                $row['deletedReason'],
                $row['supplierId'],
            );
        }

        return $rows;
    }

    private function kind(ReceivedDocumentKind|string $kind): ReceivedDocumentKind
    {
        return $kind instanceof ReceivedDocumentKind ? $kind : ReceivedDocumentKind::from($kind);
    }

    private function treatment(PurchaseTaxTreatment|string $treatment): PurchaseTaxTreatment
    {
        return $treatment instanceof PurchaseTaxTreatment ? $treatment : PurchaseTaxTreatment::from($treatment);
    }
}
