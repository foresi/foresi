<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\InvoicePage;
use App\Invoicing\Application\InvoicePayments;
use App\Invoicing\Application\InvoiceRow;
use App\Invoicing\Application\InvoiceSends;
use App\Invoicing\Application\InvoiceStore;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\PaymentState;
use App\Invoicing\Application\SendMark;
use App\Invoicing\Application\StoredInvoice;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Application\UnsupportedCountry;
use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\NumberKey;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentAllocation;
use App\Invoicing\Domain\SendPurpose;
use App\Invoicing\Domain\SendStatus;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Money;
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
 *
 * What an invoice has been paid is summed in SQL for the tabs and for overdue, one
 * subquery per use, and read in one more query for the rows of a page. A document
 * paid in full or more is settled: it is never overdue.
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
        private InvoiceSends $sends,
        private InvoicePayments $payments,
    ) {
    }

    public function get(string $id): Invoice
    {
        // A query, not find(): find() answers from the identity map without the tenant filter.
        $invoice = $this->em->createQuery('SELECT i FROM '.Invoice::class.' i WHERE i.id = :id')->setParameter('id', $id)->getOneOrNullResult();

        return $invoice instanceof Invoice ? $invoice : throw new UnknownInvoice();
    }

    public function lockRow(string $id): void
    {
        if (1 !== $this->em->getConnection()->executeStatement(self::LOCK, ['id' => $id, 'tenant' => $this->context->current()->toString()])) {
            throw new UnknownInvoice();
        }
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

        // Each kind counts its own tabs; a tab it lacks lists the whole kind.
        $tabs = InvoiceFilter::tabsOf($filter->kind);
        $tab = \in_array($filter->tab, $tabs, true) ? $filter->tab : InvoiceFilter::ALL;
        $sums = array_map(fn (string $key, int $index): string => 'SUM(CASE WHEN '.$this->inTab($key, $filter->kind, 'c'.$index, $overdue).' THEN 1 ELSE 0 END)', $tabs, array_keys($tabs));
        $counts = $this->em->createQuery('SELECT '.implode(', ', $sums).' FROM '.Invoice::class.' i WHERE '.$where);
        $this->bind($counts, \in_array(InvoiceFilter::OVERDUE, $tabs, true) ? [...$parameters, ...$overdueParameters] : $parameters);
        /** @var list<numeric-string|int|null> $values */
        $values = array_values((array) $counts->getSingleResult(Query::HYDRATE_ARRAY));
        $tabCounts = array_combine($tabs, array_map(intval(...), $values));

        $total = $tabCounts[$tab];
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($filter->page, $pages));

        $condition = $where.' AND '.$this->inTab($tab, $filter->kind, 'l', $overdue);
        $list = $this->em->createQuery('SELECT i FROM '.Invoice::class.' i WHERE '.$condition.' ORDER BY '.$this->order($filter->sort));
        $this->bind($list, InvoiceFilter::OVERDUE === $tab ? [...$parameters, ...$overdueParameters] : $parameters);

        /** @var list<Invoice> $invoices */
        $invoices = $list->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)->getResult();

        // One query for the marks of the whole page, and one for what it has been paid.
        $ids = array_map(static fn (Invoice $invoice): string => $invoice->id(), $invoices);
        $marks = $this->sends->marksFor($ids);
        $paid = $this->payments->paidFor($ids);

        return new InvoicePage(array_map(fn (Invoice $invoice): InvoiceRow => $this->row($invoice, $todays, $marks[$invoice->id()] ?? SendMark::None, $paid[$invoice->id()] ?? 0), $invoices), $total, $tabCounts, $page, $pages, $firms);
    }

    public function idsForPeriod(LegalEntity $firm, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<string> $ids */
        $ids = $this->em->createQuery('SELECT i.id FROM '.Invoice::class.' i WHERE i.firm = :firm AND i.deletedAt IS NULL AND i.taxPointDate >= :from AND i.taxPointDate <= :to ORDER BY i.numberKey, i.id')
            ->setParameter('firm', $firm->id())
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->getSingleColumnResult();

        return $ids;
    }

    public function correctionsOf(Invoice $original): array
    {
        /** @var list<Invoice> $corrections */
        $corrections = $this->em->createQuery('SELECT i FROM '.Invoice::class.' i WHERE i.correctedInvoiceId = :id AND i.deletedAt IS NULL ORDER BY i.numberKey, i.id')
            ->setParameter('id', $original->id())
            ->getResult();

        return $corrections;
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
     * What a tab of the list of a kind holds.
     */
    private function inTab(string $tab, DocumentKind $kind, string $suffix, string $overdue): string
    {
        return match ($tab) {
            InvoiceFilter::TRASH => 'i.deletedAt IS NOT NULL',
            InvoiceFilter::PAID => 'i.deletedAt IS NULL AND '.$this->paid($suffix),
            InvoiceFilter::UNPAID => 'i.deletedAt IS NULL AND '.$this->unpaid($suffix),
            InvoiceFilter::OVERDUE => 'i.deletedAt IS NULL AND '.$overdue.' AND '.$this->unpaid($suffix),
            InvoiceFilter::SETTLED => 'i.deletedAt IS NULL AND i.deductedByInvoiceId IS NOT NULL',
            InvoiceFilter::UNSETTLED => 'i.deletedAt IS NULL AND i.deductedByInvoiceId IS NULL AND '.$this->deductible($kind, $suffix),
            default => 'i.deletedAt IS NULL',
        };
    }

    /**
     * Paid in full or more: Paid and Overpaid, as a tax document on payment always is. A document
     * with nothing to pay is never settled.
     */
    private function paid(string $suffix): string
    {
        return '((i.payableMinor > 0 AND (i.kind = \''.DocumentKind::PaymentTaxDocument->value.'\' OR i.payableMinor <= '.DoctrineInvoicePayments::paidOfInvoiceDql($suffix).')) OR (i.deductsAdvances = true AND i.payableMinor <= 0))';
    }

    /**
     * Unpaid and PartlyPaid: what is still to pay, or what has nothing to pay.
     */
    private function unpaid(string $suffix): string
    {
        return '((i.payableMinor <= 0 AND i.deductsAdvances = false) OR (i.kind <> \''.DocumentKind::PaymentTaxDocument->value.'\' AND i.payableMinor > '.DoctrineInvoicePayments::paidOfInvoiceDql($suffix).'))';
    }

    /**
     * Not deducted yet, what a final invoice may still deduct: a tax document of a payment
     * not cancelled; an advance with such a tax document, or with a payment not cancelled
     * that has none, because no VAT arose on it or its tax document waits to be issued.
     */
    private function deductible(DocumentKind $kind, string $suffix): string
    {
        if (DocumentKind::PaymentTaxDocument === $kind) {
            return \sprintf('EXISTS (SELECT dp%1$s.id FROM %2$s dp%1$s WHERE dp%1$s.id = i.paymentId AND dp%1$s.cancelledAt IS NULL)', $suffix, Payment::class);
        }

        return \sprintf(
            '(EXISTS (SELECT dt%1$s.id FROM %2$s dt%1$s, %3$s dp%1$s WHERE dt%1$s.advanceInvoiceId = i.id AND dp%1$s.id = dt%1$s.paymentId AND dt%1$s.deletedAt IS NULL AND dt%1$s.deductedByInvoiceId IS NULL AND dp%1$s.cancelledAt IS NULL)'
            .' OR EXISTS (SELECT da%1$s.id FROM %4$s da%1$s JOIN da%1$s.payment dq%1$s WHERE da%1$s.invoice = i AND dq%1$s.cancelledAt IS NULL'
            .' AND NOT EXISTS (SELECT dn%1$s.id FROM %2$s dn%1$s WHERE dn%1$s.advanceInvoiceId = i.id AND dn%1$s.paymentId = dq%1$s.id AND dn%1$s.deletedAt IS NULL)))',
            $suffix,
            Invoice::class,
            Payment::class,
            PaymentAllocation::class,
        );
    }

    /**
     * What firm, text and the to-send filter leave: the tabs count within this.
     *
     * @param array<string, string> $firms
     *
     * @return array{string, array<string, array{mixed, string|null}>}
     */
    private function narrowed(InvoiceFilter $filter, array $firms): array
    {
        $where = 'i.kind = :kind';
        $parameters = ['kind' => [$filter->kind->value, null]];
        if (null !== $filter->firmId && isset($firms[$filter->firmId])) {
            $where .= ' AND i.firm = :firm';
            $parameters['firm'] = [$filter->firmId, null];
        }
        if ($filter->toSend) {
            // Issued from a template and not delivered: neither marked so nor sent or on its way; a failed send does not count.
            $where .= ' AND i.recurringInvoiceId IS NOT NULL AND i.deletedAt IS NULL AND i.deliveredAt IS NULL'
                .' AND NOT EXISTS (SELECT s.id FROM '.InvoiceSend::class.' s WHERE s.invoice = i AND s.purpose = :invoicePurpose AND s.status IN (:deliveringStatuses))';
            $parameters['invoicePurpose'] = [SendPurpose::Invoice->value, null];
            $parameters['deliveringStatuses'] = [[SendStatus::Queued->value, SendStatus::Sent->value], null];
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
    private function row(Invoice $invoice, array $todays, SendMark $mark, int $paidMinor): InvoiceRow
    {
        $today = $todays[$invoice->issuer()->address->countryCode] ?? null;
        $paid = Money::ofMinor($paidMinor, $invoice->currency());
        $payment = PaymentState::ofDocument($invoice->kind(), $invoice->payable(), $paid);
        $byAdvances = $invoice->deductsAdvances() && $invoice->payable()->minor() <= 0;

        return new InvoiceRow(
            $invoice->id(),
            $invoice->number(),
            $invoice->issuer()->name,
            $invoice->customer()->name,
            $invoice->issueDate(),
            $invoice->dueDate(),
            $invoice->payable(),
            $invoice->isDelivered(),
            !$invoice->isTrashed() && !$payment->isSettled() && !$byAdvances && null !== $today && $invoice->dueDate()->format('Y-m-d') < $today->format('Y-m-d'),
            $invoice->isTrashed(),
            $paid,
            $payment,
            null === $today ? $invoice->deletedAt() : $invoice->deletedAt()?->setTimezone($today->getTimezone()),
            $invoice->deletedBy(),
            $invoice->deletedReason(),
            $mark,
            $invoice->kind(),
            $byAdvances,
        );
    }
}
