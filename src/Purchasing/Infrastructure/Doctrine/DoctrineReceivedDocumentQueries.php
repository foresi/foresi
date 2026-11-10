<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Doctrine;

use App\Purchasing\Application\Query\ReceivedDocumentFilter;
use App\Purchasing\Application\Query\ReceivedDocumentQueries;
use App\Purchasing\Application\Query\ReceivedDocumentRow;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\ReceivedDocumentState;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * The lists of received documents in DQL over scalar columns, so a page loads
 * no document; the tenant filter keeps every query inside the active tenant.
 */
final readonly class DoctrineReceivedDocumentQueries implements ReceivedDocumentQueries
{
    private const string COLUMNS = 'd.id, f.id AS firmId, f.name AS firmName, d.state, d.kind, d.treatment, d.evidenceNumber, d.supplier.name AS supplierName, d.supplierNumber, '
        .'d.issueDate, d.taxPointDate, d.receivedDate, d.currency, d.totalMinor, d.vatCzkMinor, d.createdAt, d.deletedAt, d.deletedBy, d.deletedReason';
    /** A document from abroad belongs to the month of its issue date (ReceivedDocumentFilter::byIssueDate). */
    private const string FROM_ABROAD = '(d.kind = :abroadKind OR d.treatment IN (:abroadTreatments))';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function count(ReceivedDocumentFilter $filter): int
    {
        [$where, $parameters] = $this->narrowed($filter);
        $query = $this->em->createQuery('SELECT COUNT(d.id) FROM '.ReceivedDocument::class.' d WHERE '.$where);
        $this->bind($query, $parameters);

        return (int) $query->getSingleScalarResult();
    }

    public function rows(ReceivedDocumentFilter $filter, int $offset, int $limit): array
    {
        [$where, $parameters] = $this->narrowed($filter);
        $order = match ($filter->tab) {
            ReceivedDocumentFilter::DRAFTS => 'd.createdAt DESC, d.id DESC',
            ReceivedDocumentFilter::TRASH => 'd.deletedAt DESC, d.id DESC',
            default => 'd.receivedDate DESC, d.evidenceNumber DESC, d.id DESC',
        };
        $query = $this->em->createQuery('SELECT '.self::COLUMNS.' FROM '.ReceivedDocument::class.' d JOIN d.firm f WHERE '.$where.' ORDER BY '.$order);
        $this->bind($query, $parameters);

        return $this->rowsOf($query->setFirstResult(max(0, $offset))->setMaxResults(max(1, $limit))->getArrayResult());
    }

    public function drafts(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(d.id) FROM '.ReceivedDocument::class.' d WHERE d.state = :draft AND d.deletedAt IS NULL')
            ->setParameter('draft', ReceivedDocumentState::Draft->value)
            ->getSingleScalarResult();
    }

    public function suppliers(?string $firmId): array
    {
        $query = $this->em->createQuery('SELECT IDENTITY(d.supplierParty) AS id, d.supplier.name AS name, d.createdAt FROM '.ReceivedDocument::class.' d WHERE d.supplierParty IS NOT NULL'
            .(null === $firmId ? '' : ' AND d.firm = :firm').' ORDER BY d.createdAt ASC');
        if (null !== $firmId) {
            $query->setParameter('firm', $firmId);
        }
        $names = [];
        /** @var array{id: string, name: string} $row */
        foreach ($query->getArrayResult() as $row) {
            $names[$row['id']] = $row['name'];
        }
        asort($names, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $names;
    }

    public function months(?string $firmId): array
    {
        $query = $this->em->createQuery('SELECT DISTINCT d.kind, d.treatment, d.issueDate, d.taxPointDate FROM '.ReceivedDocument::class.' d'.(null === $firmId ? '' : ' WHERE d.firm = :firm'));
        if (null !== $firmId) {
            $query->setParameter('firm', $firmId);
        }
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

    public function correctable(string $firmId, ?string $exceptId, int $limit): array
    {
        $query = $this->em->createQuery('SELECT '.self::COLUMNS.' FROM '.ReceivedDocument::class.' d JOIN d.firm f WHERE d.firm = :firm AND d.state = :recorded AND d.deletedAt IS NULL AND d.kind <> :creditNote'
            .(null === $exceptId ? '' : ' AND d.id <> :except').' ORDER BY d.receivedDate DESC, d.evidenceNumber DESC, d.id DESC')
            ->setParameter('firm', $firmId)
            ->setParameter('recorded', ReceivedDocumentState::Recorded->value)
            ->setParameter('creditNote', ReceivedDocumentKind::CreditNote->value);
        if (null !== $exceptId) {
            $query->setParameter('except', $exceptId);
        }

        return $this->rowsOf($query->setMaxResults(max(1, $limit))->getArrayResult());
    }

    /**
     * @return array{string, array<string, array{mixed, string|null}>}
     */
    private function narrowed(ReceivedDocumentFilter $filter): array
    {
        $where = match ($filter->tab) {
            ReceivedDocumentFilter::DRAFTS => 'd.state = :state AND d.deletedAt IS NULL',
            ReceivedDocumentFilter::RECORDED => 'd.state = :state AND d.deletedAt IS NULL',
            default => 'd.deletedAt IS NOT NULL',
        };
        $parameters = [];
        if (ReceivedDocumentFilter::TRASH !== $filter->tab) {
            $parameters['state'] = [ReceivedDocumentFilter::DRAFTS === $filter->tab ? ReceivedDocumentState::Draft->value : ReceivedDocumentState::Recorded->value, null];
        }
        if (null !== $filter->firmId) {
            $where .= ' AND d.firm = :firm';
            $parameters['firm'] = [$filter->firmId, null];
        }
        if (null !== $filter->supplierId) {
            $where .= ' AND d.supplierParty = :supplier';
            $parameters['supplier'] = [$filter->supplierId, null];
        }
        if ($filter->kind instanceof ReceivedDocumentKind) {
            $where .= ' AND d.kind = :kind';
            $parameters['kind'] = [$filter->kind->value, null];
        }
        if ($filter->treatment instanceof PurchaseTaxTreatment) {
            $where .= ' AND d.treatment = :treatment';
            $parameters['treatment'] = [$filter->treatment->value, null];
        }
        if (null !== $filter->month) {
            $start = new \DateTimeImmutable($filter->month.'-01');
            $where .= ' AND (('.self::FROM_ABROAD.' AND d.issueDate >= :monthStart AND d.issueDate < :monthEnd)'
                .' OR (NOT '.self::FROM_ABROAD.' AND ((d.taxPointDate >= :monthStart AND d.taxPointDate < :monthEnd) OR (d.taxPointDate IS NULL AND d.issueDate >= :monthStart AND d.issueDate < :monthEnd))))';
            $parameters['monthStart'] = [$start, Types::DATE_IMMUTABLE];
            $parameters['monthEnd'] = [$start->modify('first day of next month'), Types::DATE_IMMUTABLE];
            $parameters['abroadKind'] = [ReceivedDocumentKind::ForeignInvoice->value, null];
            $parameters['abroadTreatments'] = [[PurchaseTaxTreatment::EuService->value, PurchaseTaxTreatment::NonEuService->value], null];
        }
        $text = trim($filter->text);
        if ('' !== $text) {
            $where .= " AND (LOWER(d.supplier.name) LIKE :text ESCAPE '!' OR LOWER(d.supplierNumber) LIKE :text ESCAPE '!' OR LOWER(d.evidenceNumber) LIKE :text ESCAPE '!')";
            $parameters['text'] = ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($text)).'%', null];
        }

        return [$where, $parameters];
    }

    /**
     * @param Query<mixed, mixed>                      $query
     * @param array<string, array{mixed, string|null}> $parameters
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
        /** @var array{id: string, firmId: string, firmName: string, state: ReceivedDocumentState|string, kind: ReceivedDocumentKind|string, treatment: PurchaseTaxTreatment|string, evidenceNumber: ?string, supplierName: string, supplierNumber: string, issueDate: ?\DateTimeImmutable, taxPointDate: ?\DateTimeImmutable, receivedDate: ?\DateTimeImmutable, currency: Currency|string, totalMinor: int|string|null, vatCzkMinor: int|string|null, createdAt: \DateTimeImmutable, deletedAt: ?\DateTimeImmutable, deletedBy: ?string, deletedReason: ?string} $row */
        foreach ($result as $row) {
            $currency = $row['currency'] instanceof Currency ? $row['currency'] : Currency::from($row['currency']);
            $rows[] = new ReceivedDocumentRow(
                $row['id'],
                $row['firmId'],
                $row['firmName'],
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
