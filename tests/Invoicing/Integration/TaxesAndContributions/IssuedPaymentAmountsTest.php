<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\TaxesAndContributions;

use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\DeductibleAdvances;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\PaymentDirection;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Invoicing\Integration\TaxesAndContributions\IssuedPaymentAmounts;
use Foresi\TaxesAndContributions\Contract\ActualAmount;
use Foresi\TaxesAndContributions\Contract\AmountKind;
use Foresi\TaxesAndContributions\Contract\OpenAmount;
use Foresi\TaxesAndContributions\Contract\VatShare;
use Foresi\Tests\Invoicing\Application\AdvanceTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The income Invoicing gives the taxes and contributions (B2, B3): each part
 * of a payment of an invoice or an advance, on the day it came, split in the
 * ratio of the document.
 */
final class IssuedPaymentAmountsTest extends AdvanceTestCase
{
    public function testAPartialPaymentSplitsNetAndVatInTheDocumentRatio(): void
    {
        // 10,000 + 2,100 VAT.
        $invoice = $this->issue->issue($this->input([$this->line('10000.00')]));
        $this->pay($invoice, '6050.00', '2026-11-10');

        $amounts = $this->paid();

        self::assertCount(1, $amounts);
        $amount = $amounts[0];
        self::assertSame([IssuedPaymentAmounts::KEY, 'invoice', $invoice->id(), AmountKind::Income], [$amount->source, $amount->subject->type, $amount->subject->id, $amount->kind]);
        self::assertSame('/api/v1/invoices/'.$invoice->id(), $amount->subject->url);
        self::assertSame([605_000, 500_000, 105_000], [$amount->gross->minor(), $amount->net->minor(), $amount->vat->minor()]);
        self::assertSame(['2026-11-10', 'Europe/Prague', '2026-11-01'], [$amount->paidOn->format('Y-m-d'), $amount->paidOn->getTimezone()->getName(), $amount->taxPointOn?->format('Y-m-d')]);
        self::assertFalse($amount->advance);
    }

    public function testOnlyThePaymentsOfTheDaysAskedCount(): void
    {
        Clock::set(new MockClock('2026-12-05 10:00:00', 'UTC'));
        $invoice = $this->issue->issue($this->input([$this->line('10000.00')]));
        $this->pay($invoice, '300.00', '2026-11-30');
        $this->pay($invoice, '100.00', '2026-10-31');
        $this->pay($invoice, '200.00', '2026-11-01');
        $this->pay($invoice, '400.00', '2026-12-01');

        self::assertSame([20_000, 30_000], array_map(static fn (ActualAmount $amount): int => $amount->gross->minor(), $this->paid()));
    }

    public function testACreditNoteRefundReducesIncome(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('1000.00')]));
        $this->pay($invoice, '1210.00', '2026-11-05');
        $this->correctionOf($this->reloaded($invoice), '-100.00');
        $this->record->record(new PaymentRequest(new \DateTimeImmutable('2026-11-20'), $this->czk('121.00'), PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoice->id(), $this->czk('121.00'))], PaymentDirection::Refund));

        $amounts = $this->paid();

        self::assertSame([[121_000, 100_000, 21_000, '2026-11-05'], [-12_100, -10_000, -2_100, '2026-11-20']], array_map(static fn (ActualAmount $amount): array => [$amount->gross->minor(), $amount->net->minor(), $amount->vat->minor(), $amount->paidOn->format('Y-m-d')], $amounts));
        self::assertSame(AmountKind::Income, $amounts[1]->kind, 'A refund is income below zero, not a cost.');
        self::assertEquals([$invoice->id() => new VatShare(90_000, 108_900)], $this->source()->sharesOf([$invoice->id()]), 'The credit note is taken into the ratio.');
    }

    public function testAnAdvanceAndItsFinalInvoiceCountOnce(): void
    {
        $advance = $this->advance([$this->gross('5000.00')]);
        $this->pay($advance, '5000.00', '2026-10-15');
        $document = $this->onlyTaxDocumentOf($advance);
        $final = $this->issue->issue($this->input([$this->gross('12000.00'), ...$this->deductionOf($document)]));
        self::assertSame(700_000, $this->reloaded($final)->payable()->minor());
        $this->pay($final, '7000.00', '2026-11-10');

        $amounts = $this->paid(from: '2026-10-01');

        self::assertSame([[$advance->id(), true, 500_000], [$final->id(), false, 700_000]], array_map(static fn (ActualAmount $amount): array => [$amount->subject->id, $amount->advance, $amount->gross->minor()], $amounts));
        self::assertSame(1_200_000, array_sum(array_map(static fn (ActualAmount $amount): int => $amount->gross->minor(), $amounts)));
        self::assertNull($amounts[0]->taxPointOn, 'An advance is no tax document.');
        self::assertEqualsWithDelta(991_736, array_sum(array_map(static fn (ActualAmount $amount): int => $amount->net->minor(), $amounts)), 1, 'The net of 12,000 with 21 % VAT, give or take the rounding of each part.');
    }

    public function testACancelledPaymentDoesNotCount(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('1000.00')]));
        $payment = $this->pay($invoice, '1210.00', '2026-11-05');
        $this->cancel->cancel($payment->id());
        $this->pay($invoice, '605.00', '2026-11-06');

        self::assertSame([60_500], array_map(static fn (ActualAmount $amount): int => $amount->gross->minor(), $this->paid()));
    }

    public function testOnePaymentOfTwoInvoicesCountsForEachInItsRatio(): void
    {
        $first = $this->issue->issue($this->input([$this->line('1000.00')]));
        $second = $this->issue->issue($this->input([$this->line('1000.00', kind: VatRateKind::Reduced)]));
        $this->pay($first, '1210.00', '2026-11-05', more: [$second->id() => '1120.00']);

        $amounts = $this->paid();

        self::assertEqualsCanonicalizing([[$first->id(), 121_000, 100_000], [$second->id(), 112_000, 100_000]], array_map(static fn (ActualAmount $amount): array => [$amount->subject->id, $amount->gross->minor(), $amount->net->minor()], $amounts));
    }

    public function testTheCallerMayReadTheDetailsWithoutATokenOrWithTheReadScopeOfInvoices(): void
    {
        self::assertTrue($this->source()->mayReadDetails());
        self::assertTrue($this->source($this->grant('invoices:read'))->mayReadDetails());
        self::assertFalse($this->source($this->grant('payments:read'))->mayReadDetails());
        self::assertSame(IssuedPaymentAmounts::KEY, $this->source()->key());
    }

    public function testSharesOfUnknownOrInvalidIdsAreNone(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('1000.00')]));

        self::assertSame([], $this->source()->sharesOf([]));
        self::assertSame([], $this->source()->sharesOf(['not-an-id']));
        self::assertEquals([$invoice->id() => new VatShare(100_000, 121_000)], $this->source()->sharesOf(['not-an-id', $invoice->id(), '019a0000-0000-7000-8000-000000000001']));
    }

    public function testWhatIsOpenAtTheEndOfADayIsWhatItsPaymentsAndCorrectionsUpToThenLeft(): void
    {
        // 1,000 + 210 VAT, supplied on 1 November.
        $invoice = $this->issue->issue($this->input([$this->line('1000.00')]));
        $this->pay($invoice, '605.00', '2026-11-10');
        $this->correctionOf($this->reloaded($invoice), '-100.00');

        self::assertSame([], $this->open('2026-10-31'), 'Not yet supplied.');
        self::assertSame([[IssuedPaymentAmounts::KEY, 'invoice', $invoice->id(), '/api/v1/invoices/'.$invoice->id(), AmountKind::Income, 121_000]], array_map(static fn (OpenAmount $amount): array => [$amount->source, $amount->subject->type, $amount->subject->id, $amount->subject->url, $amount->kind, $amount->gross->minor()], $this->open('2026-11-09')));
        self::assertSame([[60_500, 50_000, 10_500]], array_map(static fn (OpenAmount $amount): array => [$amount->gross->minor(), $amount->net->minor(), $amount->vat->minor()], $this->open('2026-11-19')), 'The payment of the 10th counts, the credit note of the 20th not yet.');
        // The credit note of 100 + 21 takes 121 off; the ratio stays 21 %.
        self::assertSame([[48_400, 40_000, 8_400]], array_map(static fn (OpenAmount $amount): array => [$amount->gross->minor(), $amount->net->minor(), $amount->vat->minor()], $this->open('2026-11-20')));
    }

    public function testAnAdvanceIsNeverOpenAndACancelledPaymentPaysNothing(): void
    {
        $this->advance([$this->gross('5000.00')]);
        $paid = $this->issue->issue($this->input([$this->line('1000.00')]));
        $this->pay($paid, '1210.00', '2026-11-05');
        $cancelled = $this->issue->issue($this->input([$this->line('1000.00')]));
        $this->cancel->cancel($this->pay($cancelled, '1210.00', '2026-11-05')->id());

        self::assertSame([[$cancelled->id(), 121_000]], array_map(static fn (OpenAmount $amount): array => [$amount->subject->id, $amount->gross->minor()], $this->open('2026-12-31')));
    }

    /**
     * @return list<OpenAmount>
     */
    private function open(string $day): array
    {
        $this->em->clear();

        return iterator_to_array($this->source()->openAt(new \DateTimeImmutable($day, new \DateTimeZone('Europe/Prague'))), false);
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

    private function source(?ApiTokenContext $tokens = null): IssuedPaymentAmounts
    {
        $container = self::getContainer();

        return $tokens instanceof ApiTokenContext ? new IssuedPaymentAmounts($container->get('doctrine.orm.entity_manager'), $tokens, $container->get(UrlGeneratorInterface::class)) : $container->get(IssuedPaymentAmounts::class);
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

    /**
     * @return list<LineInput>
     */
    private function deductionOf(Invoice $document): array
    {
        foreach (self::getContainer()->get(DeductibleAdvances::class)->for($this->customer->id(), null, DocumentLanguage::Czech) as $deductible) {
            if ($deductible->id === $document->id()) {
                return $deductible->lines;
            }
        }
        self::fail('The document is not offered.');
    }
}
