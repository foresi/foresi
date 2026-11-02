<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\InvoiceSends;
use App\Invoicing\Application\UnknownInvoiceSend;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\InvoiceSend;
use App\Invoicing\Domain\SendStatus;
use App\Tenancy\Application\TenantContext;
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
        $ids = $this->em->createQuery('SELECT s.id FROM '.InvoiceSend::class.' s WHERE s.status = :queued AND s.attempts = 0 AND s.createdAt < :before AND (s.claimedAt IS NULL OR s.claimedAt < :before) ORDER BY s.createdAt, s.id')
            ->setParameter('queued', SendStatus::Queued->value)
            ->setParameter('before', $before, 'datetime_immutable')
            ->setMaxResults($limit)
            ->getSingleColumnResult();

        return $ids;
    }
}
