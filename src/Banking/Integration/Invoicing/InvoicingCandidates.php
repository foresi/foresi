<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Integration\Invoicing;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Banking\Application\Candidate;
use Foresi\Banking\Application\Candidates;
use Foresi\Banking\Domain\BankMovement;
use Foresi\Invoicing\Contract\PayableInvoice;
use Foresi\Invoicing\Contract\PayableInvoices;
use Foresi\Shared\Domain\Currency;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The open invoices a movement may pay, as Invoicing reads them. The invoices
 * paid from a counter account are those paid from the movements of that account.
 * Replaces NoCandidates when Invoicing is installed.
 */
#[AsAlias(Candidates::class)]
final readonly class InvoicingCandidates implements Candidates
{
    public function __construct(private PayableInvoices $invoices, private EntityManagerInterface $em)
    {
    }

    public function withSymbol(string $variableSymbol): array
    {
        return $this->candidates($this->invoices->withSymbol($variableSymbol));
    }

    public function open(Currency $currency, string $text, array $ids, int $limit): array
    {
        return $this->candidates($this->invoices->open($currency, $text, $ids, $limit));
    }

    public function refunds(?string $variableSymbol): array
    {
        return $this->candidates($this->invoices->refunds($variableSymbol));
    }

    public function paidFrom(?string $counterIban, ?string $counterAccount): array
    {
        [$field, $value] = null !== $counterIban && '' !== $counterIban ? ['counterIban', $counterIban] : ['counterAccount', $counterAccount];
        if (null === $value || '' === $value) {
            return [];
        }
        /** @var list<array{id: string}> $rows */
        $rows = $this->em->createQuery('SELECT m.id AS id FROM '.BankMovement::class.' m WHERE m.'.$field.' = :value')
            ->setParameter('value', $value)
            ->getArrayResult();

        return $this->invoices->paidFrom(array_map(static fn (array $row): string => $row['id'], $rows));
    }

    /**
     * @param list<PayableInvoice> $invoices
     *
     * @return list<Candidate>
     */
    private function candidates(array $invoices): array
    {
        return array_map(static fn (PayableInvoice $invoice): Candidate => new Candidate($invoice->invoiceId, $invoice->number, $invoice->dueDate, $invoice->remaining, $invoice->customerName, $invoice->issueDate), $invoices);
    }
}
