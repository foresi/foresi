<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Integration\Margin;

use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\IssuedDocumentFactory;
use Foresi\Invoicing\Application\VatRecap;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentLine;
use Foresi\Margin\Contract\MarginRevenueSource;
use Foresi\Margin\Contract\RevenueDocument;
use Foresi\Margin\Contract\RevenueLine;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The revenue of the documents the firm issued: the same documents the VAT
 * module reads (invoices, tax documents on payment and corrective tax
 * documents not in the trash, never an advance), each on its tax point, at the
 * net of its rate summaries converted at its own rate, in whole hellers.
 *
 * A final invoice that deducts a tax document on payment carries negative
 * lines, so the advance (left out), its tax document (counted) and the final
 * invoice (net minus the deduction) count the supply once. A line that deducts
 * an advance without a tax document, as a firm not registered for VAT has, is
 * added back: that advance never counted.
 *
 * The lines add up to the document only to a heller: the document converts its
 * summaries, a line converts itself.
 */
final readonly class IssuedRevenue implements MarginRevenueSource
{
    public function __construct(
        private InvoiceStore $store,
        private IssuedDocumentFactory $documents,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): iterable
    {
        $zone = $from->getTimezone();
        $taxDocuments = array_filter($this->store->forAccountant($from, $to, false), static fn (DocumentKind $kind): bool => DocumentKind::Advance !== $kind);
        foreach (array_keys($taxDocuments) as $id) {
            $document = $this->documents->of($id);
            $invoice = $this->store->get($id);
            $zero = Money::ofMinor(0, Currency::CZK);
            $net = VatRecap::total(VatRecap::of([$document], Currency::CZK), Currency::CZK)->base;
            $lines = [];
            foreach ($this->store->lines($invoice) as $index => $stored) {
                $line = $document->lines[$index];
                $czk = self::converted($line->net(), $document->exchangeRate);
                if ($this->deductsAnUntaxedAdvance($line, $document->deposits)) {
                    $net = $net->minus($czk);
                    $czk = $zero;
                }
                $lines[] = new RevenueLine($stored->id(), null, $czk);
            }
            yield new RevenueDocument(
                $id,
                $document->number,
                $document->kind->value,
                $invoice->customerParty()->id(),
                new \DateTimeImmutable(($document->taxPointDate ?? $document->issueDate)->format('Y-m-d'), $zone),
                $net,
                $this->urls->generate('app_invoice_show', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_PATH),
                $lines,
            );
        }
    }

    /**
     * @param array<string, array{number: string, variableSymbol: string, taxed: bool}> $deposits
     */
    private function deductsAnUntaxedAdvance(DocumentLine $line, array $deposits): bool
    {
        $deducted = $line->deducts();

        return null !== $deducted && false === ($deposits[$deducted]['taxed'] ?? true);
    }

    private static function converted(Money $amount, string $rate): Money
    {
        return Money::ofMinor($amount->minor(), Currency::CZK)->multipliedBy($rate);
    }
}
