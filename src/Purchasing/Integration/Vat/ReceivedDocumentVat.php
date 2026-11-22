<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Integration\Vat;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentState;
use Foresi\Purchasing\Infrastructure\Api\ReceivedDocumentApiController;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\VatDocumentSource;
use Foresi\Vat\Contract\VatSubject;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The VAT of the recorded received documents not in the trash, in their
 * deduction month. Domestic VAT the supplier charged is deducted. In the
 * self-assessed treatments (domestic reverse charge, a service from the EU
 * or from outside it) the firm owes the VAT and deducts it in the same
 * return, so it is both output and input. Non-deductible VAT and a document
 * without VAT give nothing, as they have no deduction month, and neither
 * does a draft. A credit note's VAT is
 * below zero, so it lowers what it adds to. VAT without a CZK amount counts
 * zero and says so.
 */
final readonly class ReceivedDocumentVat implements VatDocumentSource
{
    public const string KEY = 'purchasing.received_vat';

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

    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        /** @var list<array{id: string, treatment: PurchaseTaxTreatment, vat: int|null, deduction: \DateTimeImmutable, taxPoint: \DateTimeImmutable|null, issue: \DateTimeImmutable, evidence: string|null, supplierNumber: string}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT d.id AS id, d.treatment AS treatment, d.vatCzkMinor AS vat, d.deductionPeriod AS deduction, d.taxPointDate AS taxPoint, d.issueDate AS issue, d.evidenceNumber AS evidence, d.supplierNumber AS supplierNumber'
            .' FROM '.ReceivedDocument::class.' d'
            .' WHERE d.state = :state AND d.deletedAt IS NULL AND d.deductionPeriod >= :from AND d.deductionPeriod <= :to'
            .' ORDER BY d.deductionPeriod ASC, d.evidenceNumber ASC, d.id ASC',
        )
            ->setParameter('state', ReceivedDocumentState::Recorded->value)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->getArrayResult();
        $zone = $from->getTimezone();
        $zero = Money::ofMinor(0, Currency::CZK);
        foreach ($rows as $row) {
            $vat = Money::ofMinor($row['vat'] ?? 0, Currency::CZK);
            // A recorded document without a tax point of its own is taxed on its issue date (ADR 0028).
            $taxPoint = ($row['taxPoint'] ?? $row['issue'])->format('Y-m-d');
            yield new DocumentVat(
                self::KEY,
                new VatSubject('received_document', $row['id'], $this->urls->generate(ReceivedDocumentApiController::SHOW, ['documentId' => $row['id']], UrlGeneratorInterface::ABSOLUTE_PATH)),
                $row['evidence'] ?? $row['supplierNumber'],
                new \DateTimeImmutable($row['deduction']->format('Y-m-d'), $zone),
                new \DateTimeImmutable($taxPoint, $zone),
                $row['treatment']->isSelfAssessed() ? $vat : $zero,
                $vat,
                null === $row['vat'],
            );
        }
    }
}
