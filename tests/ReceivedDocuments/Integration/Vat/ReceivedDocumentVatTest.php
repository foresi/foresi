<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ReceivedDocuments\Integration\Vat;

use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\ReceivedDocuments\Application\TrashReceivedDocument;
use Foresi\ReceivedDocuments\Domain\PurchaseTaxTreatment;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentKind;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentLineInput;
use Foresi\ReceivedDocuments\Integration\Vat\ReceivedDocumentVat;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\ReceivedDocuments\Application\PurchasingTestCase;
use Foresi\Vat\Contract\DocumentVat;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The VAT Purchasing gives the VAT module: each recorded received document
 * in its deduction month, domestic VAT as input, self-assessed VAT as both
 * output and input, and nothing for non-deductible VAT, a document without
 * VAT, a draft or the trash.
 */
final class ReceivedDocumentVatTest extends PurchasingTestCase
{
    public function testMartinsNovemberDomesticInvoiceIsInputInItsDeductionMonth(): void
    {
        // 1,000 + 210 VAT, tax point 24 November, received the 25th.
        $id = $this->recording()->create($this->input(issue: '2026-11-24', taxPoint: '2026-11-24', received: '2026-11-25'))->document->id();

        $documents = $this->between('2026-11-01', '2026-11-30');

        self::assertCount(1, $documents);
        $document = $documents[0];
        self::assertSame([ReceivedDocumentVat::KEY, 'received_document', $id, '/api/v1/received-documents/'.$id], [$document->source, $document->subject->type, $document->subject->id, $document->subject->url]);
        self::assertSame($this->document($id)->evidenceNumber(), $document->label);
        self::assertSame(['2026-11-01', '2026-11-24', 'Europe/Prague', 'Europe/Prague'], [$document->countsOn->format('Y-m-d'), $document->taxPointOn->format('Y-m-d'), $document->countsOn->getTimezone()->getName(), $document->taxPointOn->getTimezone()->getName()]);
        self::assertSame([0, 'CZK', 21_000, 'CZK', false], [$document->output->minor(), $document->output->currency()->value, $document->input->minor(), $document->input->currency()->value, $document->missingCzk]);
    }

    public function testADocumentCountsInItsDeductionMonthNotItsTaxPointMonth(): void
    {
        $this->recording()->create($this->input(issue: '2026-11-28', taxPoint: '2026-11-28', received: '2026-12-03'));

        self::assertSame([], $this->between('2026-11-01', '2026-11-30'));
        $december = $this->between('2026-12-01', '2026-12-31');
        self::assertSame(['2026-12-01', '2026-11-28'], [$december[0]->countsOn->format('Y-m-d'), $december[0]->taxPointOn->format('Y-m-d')]);
    }

    public function testOnlyTheDeductionMonthsOfTheDaysAskedCount(): void
    {
        $this->recording()->create($this->input(supplierNumber: 'NOV', issue: '2026-11-20', taxPoint: '2026-11-20', received: '2026-11-21'));
        $this->recording()->create($this->input(supplierNumber: 'OCT', issue: '2026-10-20', taxPoint: '2026-10-20', received: '2026-10-31'));
        $this->recording()->create($this->input(supplierNumber: 'DEC', issue: '2026-11-20', taxPoint: '2026-11-20', received: '2026-12-01'));

        self::assertSame(['NOV'], $this->numbers($this->between('2026-11-01', '2026-11-30')));
        self::assertSame(['OCT', 'NOV'], $this->numbers($this->between('2026-10-01', '2026-11-01')), 'By the deduction month.');
    }

    public function testSelfAssessedVatIsBothOutputAndInputInTheTaxPointMonth(): void
    {
        // An EU service with a base of 5,000 CZK at 21 %, received in December.
        $this->recording()->create($this->input(treatment: PurchaseTaxTreatment::EuService, issue: '2026-11-25', taxPoint: '2026-11-20', received: '2026-12-02', base: 500_000));

        $documents = $this->between('2026-11-01', '2026-11-30');

        self::assertSame([[105_000, 105_000, '2026-11-01', '2026-11-20']], array_map(static fn (DocumentVat $vat): array => [$vat->output->minor(), $vat->input->minor(), $vat->countsOn->format('Y-m-d'), $vat->taxPointOn->format('Y-m-d')], $documents));
    }

    public function testAReceivedCreditNoteLowersInput(): void
    {
        $original = $this->recording()->create($this->input(supplierNumber: 'FV-1'))->document->id();
        $this->recording()->create($this->input(kind: ReceivedDocumentKind::CreditNote, supplierNumber: 'DB-1', base: -10_000, originalDocumentId: $original));

        self::assertSame([[0, 21_000], [0, -2_100]], array_map(static fn (DocumentVat $vat): array => [$vat->output->minor(), $vat->input->minor()], $this->between('2026-11-01', '2026-11-30')));
    }

    public function testNonDeductibleAndNonVatDocumentsAddNothing(): void
    {
        $this->recording()->create($this->input(treatment: PurchaseTaxTreatment::NonDeductible, supplierNumber: 'NON-1'));
        $this->recording()->create($this->input(kind: ReceivedDocumentKind::Other, treatment: PurchaseTaxTreatment::NotVatDocument, supplierNumber: 'OTHER-1'));

        self::assertSame([], $this->between('2026-11-01', '2026-11-30'));
    }

    public function testTheTrashAndDraftsAddNothing(): void
    {
        $trashed = $this->recording()->create($this->input(supplierNumber: 'FV-1'))->document->id();
        self::getContainer()->get(TrashReceivedDocument::class)->trash($trashed, 'Duplicate');
        $this->editing()->edit($this->draft(), $this->input(supplierNumber: 'FV-2'));

        self::assertSame([], $this->between('2026-11-01', '2026-11-30'));
    }

    public function testMissingCzkVatCountsZeroAndSaysSo(): void
    {
        $id = $this->recording()->create($this->input())->document->id();
        $this->em->getConnection()->executeStatement('UPDATE received_document SET vat_czk_minor = NULL WHERE id = ?', [$id]);

        $documents = $this->between('2026-11-01', '2026-11-30');

        self::assertSame([[0, 0, true]], array_map(static fn (DocumentVat $vat): array => [$vat->output->minor(), $vat->input->minor(), $vat->missingCzk], $documents));
    }

    public function testLinesDoNotChangeTheVatRecap(): void
    {
        $id = $this->recording()->create($this->input(issue: '2026-11-24', taxPoint: '2026-11-24', received: '2026-11-25'))->document->id();
        $before = $this->amounts($this->between('2026-11-01', '2026-11-30'));

        $document = $this->document($id);
        $document->setLines([
            new ReceivedDocumentLineInput('Hosting', Money::ofMinor(70_000, Currency::CZK), '21'),
            new ReceivedDocumentLineInput('Domain', Money::ofMinor(30_000, Currency::CZK), '21'),
        ]);
        $this->em->flush();

        self::assertCount(2, $this->document($id)->lines());
        self::assertSame($before, $this->amounts($this->between('2026-11-01', '2026-11-30')));
    }

    public function testTheCallerMayReadTheDetailsWithoutATokenOrWithTheReadScopeOfReceivedDocuments(): void
    {
        self::assertTrue($this->source()->mayReadDetails());
        self::assertTrue($this->source($this->grant('received_documents:read'))->mayReadDetails());
        self::assertFalse($this->source($this->grant('vat:read'))->mayReadDetails());
    }

    /**
     * @param list<DocumentVat> $documents
     *
     * @return list<string>
     */
    private function numbers(array $documents): array
    {
        return array_map(fn (DocumentVat $vat): string => $this->document($vat->subject->id)->supplierNumber(), $documents);
    }

    /**
     * @param list<DocumentVat> $documents
     *
     * @return list<array{string, string, string, int, int, bool}>
     */
    private function amounts(array $documents): array
    {
        return array_map(static fn (DocumentVat $vat): array => [$vat->subject->id, $vat->countsOn->format('Y-m-d'), $vat->taxPointOn->format('Y-m-d'), $vat->output->minor(), $vat->input->minor(), $vat->missingCzk], $documents);
    }

    /**
     * @return list<DocumentVat>
     */
    private function between(string $from, string $to): array
    {
        $zone = new \DateTimeZone('Europe/Prague');
        $this->em->clear();

        return iterator_to_array($this->source()->between(new \DateTimeImmutable($from, $zone), new \DateTimeImmutable($to, $zone)), false);
    }

    private function source(?ApiTokenContext $tokens = null): ReceivedDocumentVat
    {
        $container = self::getContainer();

        return $tokens instanceof ApiTokenContext ? new ReceivedDocumentVat($container->get('doctrine.orm.entity_manager'), $tokens, $container->get(UrlGeneratorInterface::class)) : $container->get(ReceivedDocumentVat::class);
    }

    private function grant(string $scope): ApiTokenContext
    {
        return new readonly class(new ApiTokenGrant('019a0000-0000-7000-8000-000000000001', '019a0000-0000-7000-8000-0000000000aa', 'Reporting', [ApiScope::from($scope)], new \DateTimeImmutable('2026-12-01', new \DateTimeZone('UTC')))) implements ApiTokenContext {
            public function __construct(private ApiTokenGrant $grant)
            {
            }

            public function grant(): ApiTokenGrant
            {
                return $this->grant;
            }

            public function requireScope(ApiScope $scope): ApiTokenGrant
            {
                return $this->grant;
            }
        };
    }
}
