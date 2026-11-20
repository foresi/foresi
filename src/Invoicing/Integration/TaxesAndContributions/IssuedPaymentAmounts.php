<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Integration\TaxesAndContributions;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\PaymentAllocation;
use Foresi\Invoicing\Infrastructure\Api\InvoiceApiController;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\ActualAmount;
use Foresi\TaxesAndContributions\Contract\ActualAmountSource;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\Subject;
use Foresi\TaxesAndContributions\Contract\VatShare;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The income actually received (B2, B3): each part of a payment that paid an
 * invoice or an advance, on the day the payment came, with net and VAT in the
 * ratio of the document as its corrections left it. A refund counts against
 * the income on its own day. A settlement written off was never received, so
 * it does not count, and neither does a cancelled payment or a document in
 * the trash. A paid advance counts when paid; its final invoice deducts it and
 * asks only for the rest, so the money counts once.
 */
final readonly class IssuedPaymentAmounts implements ActualAmountSource
{
    public const string KEY = 'invoicing.payments';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ApiTokenContext $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function mayReadDetails(): bool
    {
        $grant = $this->tokens->grant();

        return !$grant instanceof ApiTokenGrant || $grant->allows(ApiScope::of('invoices', false));
    }

    public function paidBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        /** @var list<array{amount: int, paidOn: \DateTimeImmutable, invoice: string, kind: DocumentKind, currency: Currency, taxPointOn: ?\DateTimeImmutable}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT a.amountMinor AS amount, p.receivedOn AS paidOn, i.id AS invoice, i.kind AS kind, i.currency AS currency, i.taxPointDate AS taxPointOn'
            .' FROM '.PaymentAllocation::class.' a JOIN a.payment p JOIN a.invoice i'
            .' WHERE p.receivedOn >= :from AND p.receivedOn <= :to AND p.cancelledAt IS NULL AND i.deletedAt IS NULL AND i.kind IN (:kinds)'
            .' ORDER BY p.receivedOn ASC, p.id ASC, i.id ASC',
        )
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('kinds', [DocumentKind::Invoice->value, DocumentKind::Advance->value])
            ->getArrayResult();
        $shares = $this->sharesOf(array_column($rows, 'invoice'));
        foreach ($rows as $row) {
            $gross = Money::ofMinor($row['amount'], $row['currency']);
            [$net, $vat] = ($shares[$row['invoice']] ?? throw new \LogicException('A paid invoice has no ratio.'))->split($gross);
            yield new ActualAmount(
                self::KEY,
                new Subject('invoice', $row['invoice'], $this->urls->generate(InvoiceApiController::SHOW, ['invoiceId' => $row['invoice']], UrlGeneratorInterface::ABSOLUTE_PATH)),
                AmountKind::Income,
                new \DateTimeImmutable($row['paidOn']->format('Y-m-d'), $from->getTimezone()),
                $gross,
                $net,
                $vat,
                null === $row['taxPointOn'] ? null : new \DateTimeImmutable($row['taxPointOn']->format('Y-m-d'), $from->getTimezone()),
                DocumentKind::Advance === $row['kind'],
            );
        }
    }

    /**
     * The net and gross of each invoice with the differences of its
     * corrective tax documents, such as a credit note, added.
     */
    public function sharesOf(array $subjectIds): array
    {
        $ids = array_values($subjectIds);
        /** @var list<array{id: string, net: int, vat: int}> $documents */
        $documents = $this->entityManager->createQuery('SELECT i.id AS id, i.netMinor AS net, i.vatMinor AS vat FROM '.Invoice::class.' i WHERE i.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getArrayResult();
        /** @var list<array{id: string, net: int|numeric-string, vat: int|numeric-string}> $corrections */
        $corrections = $this->entityManager->createQuery(
            'SELECT c.correctedInvoiceId AS id, SUM(c.netMinor) AS net, SUM(c.vatMinor) AS vat FROM '.Invoice::class.' c'
            .' WHERE c.correctedInvoiceId IN (:ids) AND c.kind = :kind AND c.deletedAt IS NULL GROUP BY c.correctedInvoiceId',
        )
            ->setParameter('ids', $ids)
            ->setParameter('kind', DocumentKind::CorrectionDocument->value)
            ->getArrayResult();
        $changes = [];
        foreach ($corrections as $correction) {
            $changes[$correction['id']] = [$correction['net'], $correction['vat']];
        }
        $shares = [];
        foreach ($documents as $document) {
            [$net, $vat] = $changes[$document['id']] ?? [0, 0];
            $net += $document['net'];
            $vat += $document['vat'];
            $shares[$document['id']] = new VatShare($net, $net + $vat);
        }

        return $shares;
    }
}
