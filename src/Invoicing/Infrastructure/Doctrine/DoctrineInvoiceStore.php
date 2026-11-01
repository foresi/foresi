<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoicePage;
use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\StoredInvoice;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\NumberKey;
use App\Party\Domain\LegalEntity;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Psr\Clock\ClockInterface;

/**
 * The lock is one write that changes nothing, the same on SQLite and PostgreSQL:
 * SELECT FOR UPDATE does not exist on SQLite, where a transaction that reads first
 * fails when it starts to write. The write takes the row, or the database, until
 * the transaction ends. A statement of its own, because it names the tenant itself:
 * the tenant filter covers only queries of the ORM.
 */
final readonly class DoctrineInvoiceStore implements InvoiceStore
{
    private const string LOCK = 'UPDATE invoice SET version = version WHERE id = :id AND tenant_id = :tenant';
    private const string COUNTRY = 'i.issuer.address.countryCode';
    private const array ORDER = [
        'number' => ['i.numberKey'],
        'customer' => ['LOWER(i.customer.name)', 'i.numberKey'],
        'issueDate' => ['i.issueDate', 'i.numberKey'],
        'dueDate' => ['i.dueDate', 'i.numberKey'],
        'payable' => ['i.payableMinor', 'i.numberKey'],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private TenantContext $context,
        private InvoicingCountries $countries,
        private ClockInterface $clock,
    ) {
    }

    public function get(string $id): Invoice
    {
        // A query, not find(): find() answers from the identity map without the tenant filter.
        $invoice = $this->em->createQuery('SELECT i FROM '.Invoice::class.' i WHERE i.id = :id')->setParameter('id', $id)->getOneOrNullResult();

        return $invoice instanceof Invoice ? $invoice : throw new UnknownInvoice();
    }

    public function lock(string $id): Invoice
    {
        $this->refusePendingChanges();
        $locked = $this->em->getConnection()->executeStatement(self::LOCK, ['id' => $id, 'tenant' => $this->context->current()->toString()]);
        if (1 !== $locked) {
            throw new UnknownInvoice();
        }
        // What this process remembers may be older than the lock, the firm and the contact too.
        $this->em->clear();

        return $this->get($id);
    }

    public function snapshot(string $id): StoredInvoice
    {
        $this->refusePendingChanges();
        // A loaded invoice would win over what this statement reads.
        $this->em->clear();
        /** @var list<Invoice|InvoiceLine|null> $rows */
        $rows = $this->em->createQuery('SELECT i, l FROM '.Invoice::class.' i LEFT JOIN '.InvoiceLine::class.' l WITH l.invoice = i WHERE i.id = :id ORDER BY l.position')
            ->setParameter('id', $id)
            ->getResult();
        $invoice = $rows[0] ?? null;
        if (!$invoice instanceof Invoice) {
            throw new UnknownInvoice();
        }

        return new StoredInvoice($invoice, array_values(array_filter($rows, static fn (Invoice|InvoiceLine|null $row): bool => $row instanceof InvoiceLine)));
    }

    public function add(Invoice $invoice): void
    {
        $this->em->persist($invoice);
    }

    public function lines(Invoice $invoice): array
    {
        /** @var list<InvoiceLine> $lines */
        $lines = $this->em->createQuery('SELECT l FROM '.InvoiceLine::class.' l WHERE l.invoice = :invoice ORDER BY l.position, l.id')
            ->setParameter('invoice', $invoice->id())
            ->getResult();

        return $lines;
    }

    public function addLine(InvoiceLine $line): void
    {
        $this->em->persist($line);
    }

    public function removeLine(InvoiceLine $line): void
    {
        $this->em->remove($line);
    }

    public function addAbandoned(AbandonedNumber $number): void
    {
        $this->em->persist($number);
    }

    public function numberTaken(LegalEntity $firm, string $key, ?Invoice $except): bool
    {
        $invoices = $this->em->createQuery('SELECT COUNT(i.id) FROM '.Invoice::class.' i WHERE i.firm = :firm AND i.numberKey = :key'.($except instanceof Invoice ? ' AND i.id <> :except' : ''))
            ->setParameter('firm', $firm->id())
            ->setParameter('key', $key);
        if ($except instanceof Invoice) {
            $invoices->setParameter('except', $except->id());
        }
        if (0 < (int) $invoices->getSingleScalarResult()) {
            return true;
        }

        $abandoned = $this->em->createQuery('SELECT COUNT(a.id) FROM '.AbandonedNumber::class.' a WHERE a.firm = :firm AND a.numberKey = :key')
            ->setParameter('firm', $firm->id())
            ->setParameter('key', $key)
            ->getSingleScalarResult();

        return 0 < (int) $abandoned;
    }

    public function page(InvoiceFilter $filter): InvoicePage
    {
        [$firms, $todays] = $this->issuers();
        [$overdue, $overdueParameters] = $this->overdue($todays);
        [$where, $parameters] = $this->narrowed($filter, $firms);

        $counts = $this->em->createQuery(
            'SELECT SUM(CASE WHEN i.deletedAt IS NULL THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN i.deletedAt IS NULL AND '.$overdue.' THEN 1 ELSE 0 END), '
            .'SUM(CASE WHEN i.deletedAt IS NOT NULL THEN 1 ELSE 0 END) FROM '.Invoice::class.' i WHERE '.$where,
        );
        $this->bind($counts, [...$parameters, ...$overdueParameters]);
        /** @var list<numeric-string|int|null> $values */
        $values = array_values((array) $counts->getSingleResult(Query::HYDRATE_ARRAY));
        $tabCounts = [InvoiceFilter::ALL => (int) $values[0], InvoiceFilter::OVERDUE => (int) $values[1], InvoiceFilter::TRASH => (int) $values[2]];

        $total = $tabCounts[$filter->tab] ?? $tabCounts[InvoiceFilter::ALL];
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($filter->page, $pages));

        $condition = $where.match ($filter->tab) {
            InvoiceFilter::TRASH => ' AND i.deletedAt IS NOT NULL',
            InvoiceFilter::OVERDUE => ' AND i.deletedAt IS NULL AND '.$overdue,
            default => ' AND i.deletedAt IS NULL',
        };
        $list = $this->em->createQuery('SELECT i FROM '.Invoice::class.' i WHERE '.$condition.' ORDER BY '.$this->order($filter->sort));
        $this->bind($list, InvoiceFilter::OVERDUE === $filter->tab ? [...$parameters, ...$overdueParameters] : $parameters);

        /** @var list<Invoice> $invoices */
        $invoices = $list->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)->getResult();

        return new InvoicePage(array_map(fn (Invoice $invoice): InvoiceRow => $this->row($invoice, $todays), $invoices), $total, $tabCounts, $page, $pages, $firms);
    }

    public function lastFirmOf(string $userId): ?LegalEntity
    {
        $firm = $this->em->createQuery('SELECT f FROM '.LegalEntity::class.' f, '.Invoice::class.' i WHERE i.firm = f AND i.createdBy = :user AND f.archivedAt IS NULL ORDER BY i.createdAt DESC, i.id DESC')
            ->setParameter('user', $userId)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return $firm instanceof LegalEntity ? $firm : null;
    }

    /**
     * Starting a session over would drop these without a word.
     */
    private function refusePendingChanges(): void
    {
        $work = $this->em->getUnitOfWork();
        $work->computeChangeSets();
        if ([] !== $work->getScheduledEntityInsertions() || [] !== $work->getScheduledEntityUpdates() || [] !== $work->getScheduledEntityDeletions()) {
            throw new \LogicException('Store pending changes before working on an invoice.');
        }
    }

    /**
     * The firms with an invoice, and the moment it is now in each country they issued from.
     *
     * @return array{array<string, string>, array<string, \DateTimeImmutable>}
     */
    private function issuers(): array
    {
        /** @var list<array{id: string, name: string, country: string}> $rows */
        $rows = $this->em->createQuery('SELECT DISTINCT f.id AS id, f.name AS name, '.self::COUNTRY.' AS country FROM '.Invoice::class.' i JOIN i.firm f ORDER BY f.name, f.id')->getArrayResult();
        $firms = [];
        $todays = [];
        foreach ($rows as $row) {
            $firms[$row['id']] = $row['name'];
            $todays[$row['country']] ??= $this->todayIn($row['country']);
        }

        return [$firms, $todays];
    }

    private function todayIn(string $countryCode): \DateTimeImmutable
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        try {
            return $now->setTimezone($this->countries->for($countryCode)->timezone());
        } catch (UnsupportedCountry) {
            // A country whose rules are gone still has a day: the document stays listed.
            return $now;
        }
    }

    /**
     * @param array<string, \DateTimeImmutable> $todays by country
     *
     * @return array{string, array<string, array{mixed, string|null}>}
     */
    private function overdue(array $todays): array
    {
        $conditions = [];
        $parameters = [];
        foreach (array_keys($todays) as $index => $country) {
            $conditions[] = \sprintf('(%s = :country%d AND i.dueDate < :today%d)', self::COUNTRY, $index, $index);
            $parameters['country'.$index] = [$country, null];
            $parameters['today'.$index] = [$todays[$country], Types::DATE_IMMUTABLE];
        }

        return [[] === $conditions ? '1 = 0' : '('.implode(' OR ', $conditions).')', $parameters];
    }

    /**
     * What firm and text leave: the tabs count within this.
     *
     * @param array<string, string> $firms
     *
     * @return array{string, array<string, array{mixed, string|null}>}
     */
    private function narrowed(InvoiceFilter $filter, array $firms): array
    {
        $where = '1 = 1';
        $parameters = [];
        if (null !== $filter->firmId && isset($firms[$filter->firmId])) {
            $where .= ' AND i.firm = :firm';
            $parameters['firm'] = [$filter->firmId, null];
        }
        $text = trim($filter->text);
        if ('' === $text) {
            return [$where, $parameters];
        }
        $conditions = ['LOWER(i.number) LIKE :text ESCAPE \'!\'', 'LOWER(i.customer.name) LIKE :text ESCAPE \'!\'', 'i.variableSymbol LIKE :text ESCAPE \'!\'', 'LOWER(i.description) LIKE :text ESCAPE \'!\''];
        $parameters['text'] = ['%'.$this->like(mb_strtolower($text)).'%', null];
        $key = NumberKey::of($text);
        if ('' !== $key) {
            $conditions[] = 'i.numberKey LIKE :key ESCAPE \'!\'';
            $parameters['key'] = ['%'.$this->like($key).'%', null];
        }

        return [$where.' AND ('.implode(' OR ', $conditions).')', $parameters];
    }

    private function order(string $sort): string
    {
        $direction = str_starts_with($sort, '-') ? ' DESC' : ' ASC';
        $columns = self::ORDER[ltrim($sort, '-')] ?? self::ORDER['issueDate'];

        return implode(', ', array_map(static fn (string $column): string => $column.$direction, [...$columns, 'i.id']));
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

    private function like(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * @param array<string, \DateTimeImmutable> $todays by country
     */
    private function row(Invoice $invoice, array $todays): InvoiceRow
    {
        $today = $todays[$invoice->issuer()->address->countryCode] ?? null;

        return new InvoiceRow(
            $invoice->id(),
            $invoice->number(),
            $invoice->issuer()->name,
            $invoice->customer()->name,
            $invoice->issueDate(),
            $invoice->dueDate(),
            $invoice->payable(),
            $invoice->isDelivered(),
            !$invoice->isTrashed() && null !== $today && $invoice->dueDate()->format('Y-m-d') < $today->format('Y-m-d'),
            $invoice->isTrashed(),
            null === $today ? $invoice->deletedAt() : $invoice->deletedAt()?->setTimezone($today->getTimezone()),
            $invoice->deletedBy(),
            $invoice->deletedReason(),
        );
    }
}
