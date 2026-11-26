<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Integration\Sales;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\RecurringInvoice;
use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Sales\Contract\BillingDocument;
use Foresi\Sales\Contract\BillingDocumentSource;
use Foresi\Sales\Contract\BillingDocumentType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The invoices and recurring invoices a planned invoice of a won deal may be
 * linked to: an invoice or an advance invoice, not in the trash; a recurring
 * invoice neither ended nor in the trash.
 */
final readonly class InvoicingBillingDocuments implements BillingDocumentSource
{
    /** The kinds that bill a client for work: a tax document or a correction never fulfils a plan. */
    private const array KINDS = [DocumentKind::Invoice, DocumentKind::Advance];

    public function __construct(private EntityManagerInterface $em, private UrlGeneratorInterface $urls)
    {
    }

    public function find(BillingDocumentType $type, string $id): ?BillingDocument
    {
        if (BillingDocumentType::Invoice === $type) {
            $invoice = $this->em->find(Invoice::class, $id);

            return $invoice instanceof Invoice && \in_array($invoice->kind(), self::KINDS, true) ? $this->invoice($invoice) : null;
        }
        $template = $this->em->find(RecurringInvoice::class, $id);

        return $template instanceof RecurringInvoice ? $this->template($template) : null;
    }

    public function ofClient(BillingDocumentType $type, string $clientPartyId, int $limit): array
    {
        if (BillingDocumentType::Invoice === $type) {
            /** @var list<Invoice> $invoices */
            $invoices = $this->em->createQueryBuilder()
                ->select('i')
                ->from(Invoice::class, 'i')
                ->where('IDENTITY(i.customerParty) = :client')
                ->andWhere('i.deletedAt IS NULL')
                ->andWhere('i.kind IN (:kinds)')
                ->setParameter('client', $clientPartyId)
                ->setParameter('kinds', array_map(static fn (DocumentKind $kind): string => $kind->value, self::KINDS))
                ->orderBy('i.issueDate', 'DESC')
                ->addOrderBy('i.id', 'DESC')
                ->setMaxResults($limit)
                ->getQuery()
                ->getResult();

            return array_map($this->invoice(...), $invoices);
        }
        /** @var list<RecurringInvoice> $templates */
        $templates = $this->em->createQueryBuilder()
            ->select('r')
            ->from(RecurringInvoice::class, 'r')
            ->where('IDENTITY(r.customer) = :client')
            ->andWhere('r.deletedAt IS NULL')
            ->andWhere('r.state != :ended')
            ->setParameter('client', $clientPartyId)
            ->setParameter('ended', RecurringState::Ended->value)
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map($this->template(...), $templates);
    }

    private function invoice(Invoice $invoice): BillingDocument
    {
        return new BillingDocument(
            BillingDocumentType::Invoice,
            $invoice->id(),
            $invoice->customerParty()->id(),
            $invoice->number(),
            !$invoice->isTrashed(),
            $this->urls->generate('app_invoice_show', ['id' => $invoice->id()]),
        );
    }

    private function template(RecurringInvoice $template): BillingDocument
    {
        return new BillingDocument(
            BillingDocumentType::RecurringInvoice,
            $template->id(),
            $template->customer()->id(),
            $template->name(),
            !$template->isTrashed() && RecurringState::Ended !== $template->state(),
            $this->urls->generate('app_recurring_show', ['id' => $template->id()]),
        );
    }
}
