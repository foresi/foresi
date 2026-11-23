<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\ImportedDocumentInput;
use Foresi\Invoicing\Application\ImportedDocumentResult;
use Foresi\Invoicing\Application\ImportedTotals;
use Foresi\Invoicing\Application\ImportIssuedDocument;
use Foresi\Invoicing\Application\IssueProblem;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\UndoImportedDocuments;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\DocumentParty;
use Foresi\Invoicing\Contract\TotalRounding;
use Foresi\Invoicing\Contract\VatRegime;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Party\Domain\Address;
use Foresi\Tests\Events\Support\ReadsEventLog;

final class ImportIssuedDocumentTest extends AdvanceTestCase
{
    use ReadsEventLog;

    private const string RUN = '0192f0a0-0000-7000-8000-000000000001';

    private ImportIssuedDocument $import;
    private UndoImportedDocuments $undo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->import = self::getContainer()->get(ImportIssuedDocument::class);
        $this->undo = self::getContainer()->get(UndoImportedDocuments::class);
    }

    public function testATaxDocumentOnPaymentOrACorrectionIsNotImportedHere(): void
    {
        foreach ([DocumentKind::PaymentTaxDocument, DocumentKind::CorrectionDocument] as $kind) {
            $result = $this->import->import($this->imported(document: ['kind' => $kind]));

            self::assertSame(['invoice.problem.import_kind_unsupported'], $this->keys($result));
        }
        $taxedCorrection = $this->imported(document: ['kind' => DocumentKind::CorrectionDocument], overrides: ['taxedAdvanceId' => '0192f0a0-0000-7000-8000-0000000000ab', 'taxedPaymentId' => '0192f0a0-0000-7000-8000-0000000000ac']);
        self::assertSame(['invoice.problem.import_kind_unsupported'], $this->keys($this->import->import($taxedCorrection)), 'Only a tax document on payment taxes a payment.');
        self::assertSame(0, $this->invoiceCount());
    }

    public function testADocumentExportedThereBeforeTheImportIsTakenBack(): void
    {
        $this->import->import($this->imported(overrides: ['exportedAt' => new \DateTimeImmutable('2026-11-20 10:00:00')]));

        self::assertSame([], $this->undo->undo(self::RUN), 'Only an export after the import stands on it.');
    }

    /**
     * @param list<LineInput>|null $lines
     * @param array<string, mixed> $document  named arguments of InvoiceInput
     * @param array<string, mixed> $overrides named arguments of ImportedDocumentInput
     */
    private function imported(?array $lines = null, array $document = [], array $overrides = [], ?string $pdf = null): ImportedDocumentInput
    {
        $customer = new DocumentParty('Customer As Printed a.s.', '87654320', 'CZ87654320', new Address('Customer Street 2', 'Customer City', '200 00', 'CZ'), '', 'billing@example.com', '', '', null, null);

        return new \ReflectionClass(ImportedDocumentInput::class)->newInstanceArgs(array_merge([
            'document' => $this->input($lines, array_merge([
                'typedNumber' => '20240007',
                'customerOnDocument' => $customer,
                'issueDate' => new \DateTimeImmutable('2024-03-01'),
                'taxPointDate' => new \DateTimeImmutable('2024-03-01'),
                'dueDate' => new \DateTimeImmutable('2024-03-15'),
                'variableSymbol' => '20240007',
            ], $document)),
            'issuer' => new DocumentParty('Old Name s.r.o.', '12345695', 'CZ12345695', new Address('Old Street 5', 'Old City', '110 00', 'CZ'), '', 'office@example.com', '', '', null, null),
            'regime' => VatRegime::Payer,
            'rounding' => TotalRounding::WholeUnit,
            'createdAt' => new \DateTimeImmutable('2024-03-01 09:30:00'),
            'deliveredAt' => new \DateTimeImmutable('2024-03-02 08:00:00'),
            'exportedAt' => new \DateTimeImmutable('2024-04-05 10:00:00'),
            'expected' => new ImportedTotals(100000, 21000, 121000, 121000),
            'source' => 'idoklad',
            'externalId' => '4711',
            'runId' => self::RUN,
            'originalPdf' => $pdf,
        ], $overrides));
    }

    /**
     * @return list<string>
     */
    private function keys(ImportedDocumentResult $result): array
    {
        return array_map(static fn (IssueProblem $problem): string => $problem->key, $result->problems);
    }

    private function invoiceCount(): int
    {
        $this->em->clear();

        return (int) $this->em->createQuery('SELECT COUNT(i.id) FROM '.Invoice::class.' i')->getSingleScalarResult();
    }
}
