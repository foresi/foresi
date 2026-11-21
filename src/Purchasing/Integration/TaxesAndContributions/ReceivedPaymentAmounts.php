<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Integration\TaxesAndContributions;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\ReceivedDocumentPayment;
use Foresi\Purchasing\Domain\ReceivedDocumentState;
use Foresi\Purchasing\Infrastructure\Api\ReceivedDocumentApiController;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\ActualAmount;
use Foresi\TaxesAndContributions\Contract\ActualAmountSource;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\OpenAmount;
use Foresi\TaxesAndContributions\Contract\Subject;
use Foresi\TaxesAndContributions\Contract\VatShare;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The costs actually paid (B2, B3): each payment of a recorded received
 * document, on the day it was paid, with net and VAT in the ratio of the
 * document. Only domestic VAT the supplier charged is deducted, so only it
 * leaves the net; self-assessed VAT was never paid to the supplier, and
 * non-deductible VAT stays a cost. The money a credit note brings back
 * counts against the costs. Drafts and the trash stay out. A paid advance
 * request counts when paid.
 *
 * What is open at the end of a day are the recorded documents supplied by
 * then (their tax point, else their issue or received day) that the payments
 * up to that day have not settled; a credit note not yet refunded is open
 * below zero. An advance request is never open: it is no payable of the
 * records.
 */
final readonly class ReceivedPaymentAmounts implements ActualAmountSource
{
    public const string KEY = 'purchasing.payments';

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

        return !$grant instanceof ApiTokenGrant || $grant->allows(ApiScope::of('received_documents', false));
    }

    public function paidBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        /** @var list<array{amount: int, paidOn: \DateTimeImmutable, currency: Currency, document: string, kind: ReceivedDocumentKind, treatment: PurchaseTaxTreatment, vat: int, total: int|null, taxPointOn: ?\DateTimeImmutable}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT p.amountMinor AS amount, p.paidOn AS paidOn, p.currency AS currency, d.id AS document, d.kind AS kind, d.treatment AS treatment, d.vatMinor AS vat, d.totalMinor AS total, d.taxPointDate AS taxPointOn'
            .' FROM '.ReceivedDocumentPayment::class.' p JOIN p.document d'
            .' WHERE p.paidOn >= :from AND p.paidOn <= :to AND d.state = :state AND d.deletedAt IS NULL'
            .' ORDER BY p.paidOn ASC, p.createdAt ASC, p.id ASC',
        )
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->setParameter('state', ReceivedDocumentState::Recorded->value)
            ->getArrayResult();
        $zone = $from->getTimezone();
        foreach ($rows as $row) {
            $paid = $row['amount'];
            $gross = Money::ofMinor(ReceivedDocumentKind::CreditNote === $row['kind'] ? -$paid : $paid, $row['currency']);
            [$net, $vat] = $this->share($row['treatment'], $row['vat'], $row['total'])->split($gross);
            yield new ActualAmount(
                self::KEY,
                new Subject('received_document', $row['document'], $this->urls->generate(ReceivedDocumentApiController::SHOW, ['documentId' => $row['document']], UrlGeneratorInterface::ABSOLUTE_PATH)),
                AmountKind::Cost,
                new \DateTimeImmutable($row['paidOn']->format('Y-m-d'), $zone),
                $gross,
                $net,
                $vat,
                null === $row['taxPointOn'] ? null : new \DateTimeImmutable($row['taxPointOn']->format('Y-m-d'), $zone),
                ReceivedDocumentKind::AdvanceRequest === $row['kind'],
            );
        }
    }

    public function openAt(\DateTimeImmutable $day): iterable
    {
        /** @var list<array{id: string, currency: Currency, kind: ReceivedDocumentKind, treatment: PurchaseTaxTreatment, vat: int, total: int|null, paid: int|numeric-string}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT d.id AS id, d.currency AS currency, d.kind AS kind, d.treatment AS treatment, d.vatMinor AS vat, d.totalMinor AS total,'
            .' (SELECT COALESCE(SUM(p.amountMinor), 0) FROM '.ReceivedDocumentPayment::class.' p WHERE p.document = d AND p.paidOn <= :day) AS paid'
            .' FROM '.ReceivedDocument::class.' d WHERE d.state = :state AND d.deletedAt IS NULL AND d.kind <> :advance AND COALESCE(d.taxPointDate, d.issueDate, d.receivedDate) <= :day',
        )
            ->setParameter('day', $day->format('Y-m-d'))
            ->setParameter('state', ReceivedDocumentState::Recorded->value)
            ->setParameter('advance', ReceivedDocumentKind::AdvanceRequest->value)
            ->getArrayResult();
        foreach ($rows as $row) {
            $share = $this->share($row['treatment'], $row['vat'], $row['total']);
            // A credit note asks below zero, and what it brings back is paid above zero.
            $paid = (int) $row['paid'];
            $rest = $share->grossMinor + (ReceivedDocumentKind::CreditNote === $row['kind'] ? $paid : -$paid);
            if (0 === $rest) {
                continue;
            }
            $gross = Money::ofMinor($rest, $row['currency']);
            [$net, $vat] = $share->split($gross);
            yield new OpenAmount(
                self::KEY,
                new Subject('received_document', $row['id'], $this->urls->generate(ReceivedDocumentApiController::SHOW, ['documentId' => $row['id']], UrlGeneratorInterface::ABSOLUTE_PATH)),
                AmountKind::Cost,
                $gross,
                $net,
                $vat,
            );
        }
    }

    public function sharesOf(array $subjectIds): array
    {
        /** @var list<array{id: string, treatment: PurchaseTaxTreatment, vat: int, total: int|null}> $documents */
        $documents = $this->entityManager->createQuery('SELECT d.id AS id, d.treatment AS treatment, d.vatMinor AS vat, d.totalMinor AS total FROM '.ReceivedDocument::class.' d WHERE d.id IN (:ids) AND d.state = :state')
            ->setParameter('ids', $subjectIds)
            ->setParameter('state', ReceivedDocumentState::Recorded->value)
            ->getArrayResult();
        $shares = [];
        foreach ($documents as $document) {
            $shares[$document['id']] = $this->share($document['treatment'], $document['vat'], $document['total']);
        }

        return $shares;
    }

    /**
     * The gross is what a recorded document asks to be paid. Only domestic VAT
     * the supplier charged is deducted from it.
     */
    private function share(PurchaseTaxTreatment $treatment, int $vat, ?int $total): VatShare
    {
        $gross = $total ?? throw new \LogicException('A recorded document has a total.');

        return new VatShare(PurchaseTaxTreatment::Domestic === $treatment ? $gross - $vat : $gross, $gross);
    }
}
