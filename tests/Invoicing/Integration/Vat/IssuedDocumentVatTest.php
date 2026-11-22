<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Vat;

use Foresi\Identity\Application\ApiTokenContext;
use Foresi\Identity\Application\ApiTokenGrant;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\IssuedDocumentFactory;
use Foresi\Invoicing\Integration\Vat\IssuedDocumentVat;
use Foresi\Tests\Invoicing\Application\AdvanceTestCase;
use Foresi\Vat\Contract\DocumentVat;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The output VAT Invoicing gives the VAT module: each tax document on its
 * tax point, paid or not, with the VAT of its rate summaries; an advance
 * adds nothing and the tax document on its payment does.
 */
final class IssuedDocumentVatTest extends AdvanceTestCase
{
    public function testMartinsNovemberInvoiceGivesItsVatOnItsTaxPoint(): void
    {
        // 10,000 + 2,100 VAT, not paid.
        $invoice = $this->issue->issue($this->input([$this->line('10000.00')], ['taxPointDate' => new \DateTimeImmutable('2026-11-20')]));

        $documents = $this->between('2026-11-01', '2026-11-30');

        self::assertCount(1, $documents);
        $document = $documents[0];
        self::assertSame([IssuedDocumentVat::KEY, 'invoice', $invoice->id(), '/api/v1/invoices/'.$invoice->id()], [$document->source, $document->subject->type, $document->subject->id, $document->subject->url]);
        self::assertSame($this->reloaded($invoice)->number(), $document->label);
        self::assertSame(['2026-11-20', '2026-11-20', 'Europe/Prague'], [$document->countsOn->format('Y-m-d'), $document->taxPointOn->format('Y-m-d'), $document->countsOn->getTimezone()->getName()]);
        self::assertSame([210_000, 'CZK', 0, 'CZK', false], [$document->output->minor(), $document->output->currency()->value, $document->input->minor(), $document->input->currency()->value, $document->missingCzk]);
    }

    public function testOnlyTheTaxPointsOfTheDaysAskedCount(): void
    {
        $this->issue->issue($this->input([$this->line('100.00')], ['taxPointDate' => new \DateTimeImmutable('2026-10-31')]));
        $this->issue->issue($this->input([$this->line('200.00')], ['taxPointDate' => new \DateTimeImmutable('2026-11-01')]));
        $this->issue->issue($this->input([$this->line('300.00')], ['taxPointDate' => new \DateTimeImmutable('2026-11-30')]));
        $this->issue->issue($this->input([$this->line('400.00')], ['taxPointDate' => new \DateTimeImmutable('2026-12-01')]));

        self::assertSame([4_200, 6_300], $this->outputs('2026-11-01', '2026-11-30'));
    }

    public function testAnAdvanceInvoiceAddsNothingAndItsPaymentTaxDocumentCountsAtItsTaxPoint(): void
    {
        $advance = $this->advance([$this->gross('1210.00')]);
        $this->pay($advance, '1210.00', '2026-11-18');
        $document = $this->onlyTaxDocumentOf($advance);

        $documents = $this->between('2026-11-01', '2026-11-30');

        self::assertSame([$document->id()], array_map(static fn (DocumentVat $vat): string => $vat->subject->id, $documents));
        self::assertSame(['2026-11-18', 21_000], [$documents[0]->taxPointOn->format('Y-m-d'), $documents[0]->output->minor()]);
    }

    public function testACorrectiveCreditLowersOutputInItsOwnPeriod(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('1000.00')], ['taxPointDate' => new \DateTimeImmutable('2026-10-20')]));
        $this->correctionOf($this->reloaded($invoice), '-1000.00');

        self::assertSame([-21_000], $this->outputs('2026-11-01', '2026-11-30'), 'The credit note of 20 November counts in November.');
        self::assertSame([21_000], $this->outputs('2026-10-01', '2026-10-31'));
    }

    public function testTheTrashAddsNothing(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('1000.00')]));
        $this->trash->trash($invoice->id(), 'Wrong customer');

        self::assertSame([], $this->between('2026-11-01', '2026-11-30'));
    }

    public function testTheCallerMayReadTheDetailsWithoutATokenOrWithTheReadScopeOfInvoices(): void
    {
        self::assertTrue($this->source()->mayReadDetails());
        self::assertTrue($this->source($this->grant('invoices:read'))->mayReadDetails());
        self::assertFalse($this->source($this->grant('vat:read'))->mayReadDetails());
    }

    /**
     * @return list<int>
     */
    private function outputs(string $from, string $to): array
    {
        return array_map(static fn (DocumentVat $vat): int => $vat->output->minor(), $this->between($from, $to));
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

    private function source(?ApiTokenContext $tokens = null): IssuedDocumentVat
    {
        $container = self::getContainer();

        return $tokens instanceof ApiTokenContext
            ? new IssuedDocumentVat($container->get(InvoiceStore::class), $container->get(IssuedDocumentFactory::class), $tokens, $container->get(UrlGeneratorInterface::class))
            : $container->get(IssuedDocumentVat::class);
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
