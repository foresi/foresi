<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Integration\TaxesAndContributions;

use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Purchasing\Application\PayReceivedDocument;
use Foresi\Purchasing\Domain\PaymentMethod;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Integration\TaxesAndContributions\ReceivedPaymentAmounts;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\ActualAmount;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\VatShare;
use Foresi\Tests\Purchasing\Application\PurchasingTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The costs Purchasing gives the taxes and contributions (B2, B3): each
 * payment of a recorded received document, on its day, split in the ratio of
 * the document, with only the VAT the supplier charged and the firm deducts
 * taken out of the net.
 */
final class ReceivedPaymentAmountsTest extends PurchasingTestCase
{
    public function testAPartialPaymentSplitsNetAndVatInTheDocumentRatio(): void
    {
        // 1,000 + 210 VAT.
        $document = $this->recording()->create($this->input())->document->id();
        $this->pay($document, '605.00', '2026-11-05');

        $amounts = $this->paid();

        self::assertCount(1, $amounts);
        $amount = $amounts[0];
        self::assertSame([ReceivedPaymentAmounts::KEY, 'received_document', $document, AmountKind::Cost], [$amount->source, $amount->subject->type, $amount->subject->id, $amount->kind]);
        self::assertSame('/api/v1/received-documents/'.$document, $amount->subject->url);
        self::assertSame([60_500, 50_000, 10_500], [$amount->gross->minor(), $amount->net->minor(), $amount->vat->minor()]);
        self::assertSame(['2026-11-05', 'Europe/Prague', '2026-10-28'], [$amount->paidOn->format('Y-m-d'), $amount->paidOn->getTimezone()->getName(), $amount->taxPointOn?->format('Y-m-d')]);
        self::assertFalse($amount->advance);
    }

    public function testOnlyThePaymentsOfTheDaysAskedCount(): void
    {
        $document = $this->recording()->create($this->input())->document->id();
        $this->pay($document, '1.00', '2026-10-31');
        $this->pay($document, '2.00', '2026-11-01');
        $this->pay($document, '3.00', '2026-11-10');

        self::assertSame([200, 300], array_map(static fn (ActualAmount $amount): int => $amount->gross->minor(), $this->paid(to: '2026-11-10')));
        self::assertSame([200], array_map(static fn (ActualAmount $amount): int => $amount->gross->minor(), $this->paid(to: '2026-11-09')));
    }

    public function testTheMoneyACreditNoteBringsBackReducesTheCosts(): void
    {
        $original = $this->recording()->create($this->input())->document->id();
        $note = $this->recording()->create($this->input(kind: ReceivedDocumentKind::CreditNote, supplierNumber: 'DB-1', base: -10_000, originalDocumentId: $original))->document->id();
        $this->pay($note, '121.00', '2026-11-06');

        $amounts = $this->paid();

        self::assertSame([[$note, AmountKind::Cost, -12_100, -10_000, -2_100]], array_map(static fn (ActualAmount $amount): array => [$amount->subject->id, $amount->kind, $amount->gross->minor(), $amount->net->minor(), $amount->vat->minor()], $amounts));
    }

    public function testSelfAssessedAndNonDeductibleVatStaysInTheNet(): void
    {
        $service = $this->recording()->create($this->input(treatment: PurchaseTaxTreatment::EuService, supplierNumber: 'EU-1'))->document->id();
        $lunch = $this->recording()->create($this->input(treatment: PurchaseTaxTreatment::NonDeductible, supplierNumber: 'NON-1'))->document->id();
        $this->pay($service, '1000.00', '2026-11-05');
        $this->pay($lunch, '1210.00', '2026-11-05');

        $amounts = [];
        foreach ($this->paid() as $amount) {
            $amounts[$amount->subject->id] = [$amount->gross->minor(), $amount->net->minor(), $amount->vat->minor()];
        }

        self::assertSame([100_000, 100_000, 0], $amounts[$service]);
        self::assertSame([121_000, 121_000, 0], $amounts[$lunch]);
    }

    public function testAPaidAdvanceRequestCountsWhenPaid(): void
    {
        $advance = $this->recording()->create($this->input(kind: ReceivedDocumentKind::AdvanceRequest, treatment: PurchaseTaxTreatment::NotVatDocument, supplierNumber: 'ZF-1', taxPoint: null))->document->id();
        $this->pay($advance, '1000.00', '2026-11-04');

        $amounts = $this->paid();

        self::assertSame([[$advance, true, 100_000, 100_000]], array_map(static fn (ActualAmount $amount): array => [$amount->subject->id, $amount->advance, $amount->gross->minor(), $amount->net->minor()], $amounts));
    }

    public function testTheCallerMayReadTheDetailsWithoutATokenOrWithTheReadScopeOfReceivedDocuments(): void
    {
        self::assertTrue($this->source()->mayReadDetails());
        self::assertTrue($this->source($this->grant('received_documents:read'))->mayReadDetails());
        self::assertFalse($this->source($this->grant('invoices:read'))->mayReadDetails());
        self::assertSame(ReceivedPaymentAmounts::KEY, $this->source()->key());
    }

    public function testSharesOfTheDocumentsAsked(): void
    {
        $domestic = $this->recording()->create($this->input())->document->id();
        $service = $this->recording()->create($this->input(treatment: PurchaseTaxTreatment::EuService, supplierNumber: 'EU-1'))->document->id();

        self::assertSame([], $this->source()->sharesOf([]));
        self::assertSame([], $this->source()->sharesOf(['not-an-id']));
        $shares = $this->source()->sharesOf(['not-an-id', $domestic, $service]);
        ksort($shares);
        $expected = [$domestic => new VatShare(100_000, 121_000), $service => new VatShare(100_000, 100_000)];
        ksort($expected);
        self::assertEquals($expected, $shares);
    }

    /**
     * @return list<ActualAmount>
     */
    private function paid(string $from = '2026-11-01', string $to = '2026-11-30'): array
    {
        $zone = new \DateTimeZone('Europe/Prague');
        $this->em->clear();

        return iterator_to_array($this->source()->paidBetween(new \DateTimeImmutable($from, $zone), new \DateTimeImmutable($to, $zone)), false);
    }

    private function pay(string $document, string $amount, string $on): void
    {
        self::getContainer()->get(PayReceivedDocument::class)->pay($document, new \DateTimeImmutable($on), Money::of($amount, Currency::CZK), PaymentMethod::BankTransfer);
    }

    private function source(?ApiTokenContext $tokens = null): ReceivedPaymentAmounts
    {
        $container = self::getContainer();

        return null === $tokens ? $container->get(ReceivedPaymentAmounts::class) : new ReceivedPaymentAmounts($container->get('doctrine.orm.entity_manager'), $tokens, $container->get(UrlGeneratorInterface::class));
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
