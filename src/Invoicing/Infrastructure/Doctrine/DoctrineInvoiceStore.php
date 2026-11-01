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
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceLine;
use App\Party\Domain\LegalEntity;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineInvoiceStore implements InvoiceStore
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function get(string $id): Invoice
    {
        // A query, not find(): find() answers from the identity map without the tenant filter.
        $invoice = $this->em->createQuery('SELECT i FROM '.Invoice::class.' i WHERE i.id = :id')->setParameter('id', $id)->getOneOrNullResult();

        return $invoice instanceof Invoice ? $invoice : throw new UnknownInvoice();
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
        $where = 'i.deletedAt IS '.($filter->trashed ? 'NOT ' : '').'NULL'.(null === $filter->firmId ? '' : ' AND i.firm = :firm');

        $count = $this->em->createQuery('SELECT COUNT(i.id) FROM '.Invoice::class.' i WHERE '.$where);
        $list = $this->em->createQuery('SELECT i FROM '.Invoice::class.' i WHERE '.$where.' ORDER BY i.issueDate DESC, i.id DESC');
        if (null !== $filter->firmId) {
            $count->setParameter('firm', $filter->firmId);
            $list->setParameter('firm', $filter->firmId);
        }
        $total = (int) $count->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($filter->page, $pages));

        /** @var list<Invoice> $invoices */
        $invoices = $list->setFirstResult(($page - 1) * self::PER_PAGE)->setMaxResults(self::PER_PAGE)->getResult();

        return new InvoicePage(array_map($this->row(...), $invoices), $total, $page, $pages);
    }

    public function lastFirmOf(string $userId): ?LegalEntity
    {
        $firm = $this->em->createQuery('SELECT f FROM '.LegalEntity::class.' f, '.Invoice::class.' i WHERE i.firm = f AND i.createdBy = :user AND f.archivedAt IS NULL ORDER BY i.createdAt DESC, i.id DESC')
            ->setParameter('user', $userId)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return $firm instanceof LegalEntity ? $firm : null;
    }

    private function row(Invoice $invoice): InvoiceRow
    {
        return new InvoiceRow(
            $invoice->id(),
            $invoice->number(),
            $invoice->issuer()->name,
            $invoice->customer()->name,
            $invoice->issueDate(),
            $invoice->dueDate(),
            $invoice->payable(),
            $invoice->isDelivered(),
            $invoice->isTrashed(),
        );
    }
}
