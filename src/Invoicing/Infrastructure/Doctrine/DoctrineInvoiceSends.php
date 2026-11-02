<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\InvoiceSends;
use App\Invoicing\Application\SendLogFilter;
use App\Invoicing\Application\SendLogPage;
use App\Invoicing\Application\SendLogRow;
use App\Invoicing\Application\SendMark;
use App\Invoicing\Application\UnknownInvoiceSend;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\SendStatus;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sends through the tenant filter. Trash and restore change the rows one by one,
 * so the audit log records them.
 */
final readonly class DoctrineInvoiceSends implements InvoiceSends
{
    private const string LOCK = 'UPDATE invoice_send SET attempts = attempts WHERE id = :id AND tenant_id = :tenant';

    public function __construct(private EntityManagerInterface $em, private TenantContext $context)
    {
    }

    public function add(InvoiceSend $send): void
    {
        $this->em->persist($send);
    }

    public function lock(string $id): InvoiceSend
    {
        $locked = $this->em->getConnection()->executeStatement(self::LOCK, ['id' => $id, 'tenant' => $this->context->current()->toString()]);
        if (1 !== $locked) {
            throw new UnknownInvoiceSend();
        }
        // What this process remembers of the row may be older than the lock. Its frozen
        // fields are readonly, which a refresh cannot write, so it is loaded anew.
        $remembered = $this->em->getUnitOfWork()->tryGetById($id, InvoiceSend::class);
        if ($remembered instanceof InvoiceSend) {
            $this->em->detach($remembered);
        }
        $send = $this->em->createQuery('SELECT s FROM '.InvoiceSend::class.' s WHERE s.id = :id')
            ->setParameter('id', $id)
            ->getOneOrNullResult();

        return $send instanceof InvoiceSend ? $send : throw new UnknownInvoiceSend();
    }

    public function ofInvoice(Invoice $invoice): array
    {
        /** @var list<InvoiceSend> $sends */
        $sends = $this->em->createQuery('SELECT s FROM '.InvoiceSend::class.' s WHERE s.invoice = :invoice ORDER BY s.createdAt, s.id')
            ->setParameter('invoice', $invoice->id())
            ->getResult();

        return $sends;
    }

    public function marksFor(array $invoiceIds): array
    {
        if ([] === $invoiceIds) {
            return [];
        }
        /** @var list<array{invoice: string, status: SendStatus|string, readAt: ?\DateTimeImmutable}> $rows */
        $rows = $this->em->createQuery('SELECT IDENTITY(s.invoice) AS invoice, s.status AS status, s.firstReadAt AS readAt FROM '.InvoiceSend::class.' s WHERE s.invoice IN (:ids) AND s.status <> :cancelled ORDER BY s.createdAt, s.id')
            ->setParameter('ids', $invoiceIds)
            ->setParameter('cancelled', SendStatus::Cancelled->value)
            ->getArrayResult();
        $newest = [];
        $read = [];
        foreach ($rows as $row) {
            $newest[$row['invoice']] = $row['status'] instanceof SendStatus ? $row['status'] : SendStatus::from($row['status']);
            $read[$row['invoice']] = ($read[$row['invoice']] ?? false) || null !== $row['readAt'];
        }
        $marks = [];
        foreach ($invoiceIds as $id) {
            $marks[$id] = SendMark::of($newest[$id] ?? null, $read[$id] ?? false);
        }

        return $marks;
    }

    public function page(SendLogFilter $filter): SendLogPage
    {
        /** @var list<array{id: string, name: string}> $firmRows */
        $firmRows = $this->em->createQuery('SELECT DISTINCT f.id AS id, f.name AS name FROM '.InvoiceSend::class.' s JOIN s.invoice i JOIN i.firm f ORDER BY f.name, f.id')->getArrayResult();
        $firms = array_column($firmRows, 'name', 'id');

        $where = '1 = 1';
        $parameters = [];
        if (null !== $filter->firmId && isset($firms[$filter->firmId])) {
            $where .= ' AND i.firm = :firm';
            $parameters['firm'] = $filter->firmId;
        }
        if ($filter->status instanceof SendStatus) {
            $where .= ' AND s.status = :status';
            $parameters['status'] = $filter->status->value;
        }
        $count = $this->em->createQuery('SELECT COUNT(s.id) FROM '.InvoiceSend::class.' s JOIN s.invoice i WHERE '.$where);
        foreach ($parameters as $name => $value) {
            $count->setParameter($name, $value);
        }
        $total = (int) $count->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / SendLogFilter::PER_PAGE));
        $page = max(1, min($filter->page, $pages));

        // The invoice comes along, for the country of its issuer.
        $list = $this->em->createQuery('SELECT s, i FROM '.InvoiceSend::class.' s JOIN s.invoice i WHERE '.$where.' ORDER BY s.createdAt DESC, s.id DESC');
        foreach ($parameters as $name => $value) {
            $list->setParameter($name, $value);
        }
        /** @var list<InvoiceSend> $sends */
        $sends = $list->setFirstResult(($page - 1) * SendLogFilter::PER_PAGE)->setMaxResults(SendLogFilter::PER_PAGE)->getResult();

        return new SendLogPage(array_map(static fn (InvoiceSend $send): SendLogRow => new SendLogRow(
            $send->id(),
            $send->invoice()->id(),
            $send->createdAt(),
            $send->issuerName(),
            $send->invoice()->issuer()->address->countryCode,
            $send->documentNumber(),
            $send->customerName(),
            [...$send->to(), ...$send->cc()],
            $send->status(),
            $send->firstReadAt(),
            $send->lastError(),
            $send->lastErrorCode(),
            $send->lastErrorSmtp(),
        ), $sends), $total, $page, $pages, $firms);
    }

    public function failing(): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(DISTINCT i.id) FROM '.InvoiceSend::class.' s JOIN s.invoice i WHERE s.status = :failed AND i.deletedAt IS NULL '
            .'AND NOT EXISTS (SELECT l.id FROM '.InvoiceSend::class.' l WHERE l.invoice = s.invoice AND l.status = :sent AND l.createdAt > s.createdAt)',
        )
            ->setParameter('failed', SendStatus::Failed->value)
            ->setParameter('sent', SendStatus::Sent->value)
            ->getSingleScalarResult();
    }

    public function untriedBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(s.id) FROM '.InvoiceSend::class.' s WHERE s.status = :queued AND s.attempts = 0 AND s.createdAt < :before')
            ->setParameter('queued', SendStatus::Queued->value)
            ->setParameter('before', $before, 'datetime_immutable')
            ->getSingleScalarResult();
    }

    public function retrying(): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(s.id) FROM '.InvoiceSend::class.' s WHERE s.status = :queued AND s.attempts > 0')
            ->setParameter('queued', SendStatus::Queued->value)
            ->getSingleScalarResult();
    }

    public function hasQueued(Invoice $invoice): bool
    {
        return (int) $this->em->createQuery('SELECT COUNT(s.id) FROM '.InvoiceSend::class.' s WHERE s.invoice = :invoice AND s.status = :queued')
            ->setParameter('invoice', $invoice->id())
            ->setParameter('queued', SendStatus::Queued->value)
            ->getSingleScalarResult() > 0;
    }

    public function revokeAll(Invoice $invoice, \DateTimeImmutable $at): void
    {
        foreach ($this->ofInvoice($invoice) as $send) {
            $send->revoke($at);
        }
    }

    public function cancelQueued(Invoice $invoice, \DateTimeImmutable $at): void
    {
        foreach ($this->ofInvoice($invoice) as $send) {
            $send->cancel($at);
        }
    }

    public function stuck(\DateTimeImmutable $before, int $limit): array
    {
        /** @var list<string> $ids */
        $ids = $this->em->createQuery(
            'SELECT s.id FROM '.InvoiceSend::class.' s'
            .' WHERE (s.requeuedAt IS NULL OR s.requeuedAt < :before) AND ('
            .' (s.status = :queued AND s.attempts = 0 AND s.claimedAt IS NULL AND s.createdAt < :before)'
            .' OR (s.status IN (:open) AND s.claimedAt < :before AND (s.lastAttemptAt IS NULL OR s.lastAttemptAt < :before)))'
            .' ORDER BY s.createdAt, s.id',
        )
            ->setParameter('queued', SendStatus::Queued->value)
            ->setParameter('open', [SendStatus::Queued->value, SendStatus::Sent->value])
            ->setParameter('before', $before, 'datetime_immutable')
            ->setMaxResults($limit)
            ->getSingleColumnResult();

        return $ids;
    }

    public function markRequeued(array $ids, \DateTimeImmutable $at): void
    {
        if ([] === $ids) {
            return;
        }
        // Bookkeeping of the job, past the audit log.
        $this->em->getConnection()->executeStatement(
            'UPDATE invoice_send SET requeued_at = :at WHERE tenant_id = :tenant AND id IN (:ids)',
            ['at' => $at, 'tenant' => $this->context->current()->toString(), 'ids' => $ids],
            ['at' => 'datetime_immutable', 'ids' => ArrayParameterType::STRING],
        );
    }
}
