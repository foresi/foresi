<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ReceivedDocuments\Integration\Margin;

use Foresi\Margin\Application\CostShareCalculation;
use Foresi\Margin\Application\CostSources;
use Foresi\Margin\Application\KnownGaps;
use Foresi\Margin\Contract\CostDocument;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\DocumentAllocation;
use Foresi\Margin\Domain\Recipient;
use Foresi\ReceivedDocuments\Application\RestoreReceivedDocument;
use Foresi\ReceivedDocuments\Application\TrashReceivedDocument;
use Foresi\ReceivedDocuments\Domain\ExchangeRate;
use Foresi\ReceivedDocuments\Domain\PurchaseTaxTreatment;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentKind;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentLineInput;
use Foresi\ReceivedDocuments\Integration\Margin\ReceivedDocumentCosts;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\ReceivedDocuments\Application\PurchasingTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * The costs Purchasing gives Margin, and the shares Margin keeps of them as
 * received documents are recorded, edited, trashed and restored.
 */
final class ReceivedDocumentCostsTest extends PurchasingTestCase
{
    public function testARecordedDocumentGivesItsLinesItsMonthAndItsLabel(): void
    {
        $id = $this->recording()->create($this->input(issue: '2026-11-24', taxPoint: '2026-11-30'))->document->id();
        $this->em->clear();

        $document = $this->costs()->document($id);

        self::assertInstanceOf(CostDocument::class, $document);
        self::assertSame([$id, $this->supplier->id(), '2026-11-30', false, '/received-documents/'.$id], [$document->id, $document->supplierId, $document->monthBasis->format('Y-m-d'), $document->trashed, $document->url]);
        self::assertSame($this->em->find(\Foresi\ReceivedDocuments\Domain\ReceivedDocument::class, $id)?->evidenceNumber().' Supplier Example s.r.o.', $document->label);
        self::assertSame(['rate:21', 100_000], [$document->lines[0]->key, $document->lines[0]->baseCzk?->minor()]);
    }

    public function testTheMonthFallsBackToTheIssueDateWithoutATaxPoint(): void
    {
        $id = $this->recording()->create($this->input(issue: '2026-10-28', taxPoint: null))->document->id();

        self::assertSame('2026-10-28', $this->costs()->document($id)?->monthBasis->format('Y-m-d'));
    }

    public function testADraftAndAnUnknownIdAreNoCostDocuments(): void
    {
        self::assertNull($this->costs()->document($this->draft()));
        self::assertNull($this->costs()->document(Uuid::v7()->toRfc4122()));
        self::assertNull($this->costs()->document('not-an-id'));
    }

    public function testRecordingTrashingAndRestoringKeepTheSharesInStep(): void
    {
        $id = $this->recording()->create($this->input(issue: '2026-11-24', taxPoint: '2026-11-24'))->document->id();
        self::assertSame(['rate:21 - 100000 unassigned 2026-11-01'], $this->shares());

        $this->em->persist(new DocumentAllocation(Uuid::v7()->toRfc4122(), $id, Recipient::own(), new \DateTimeImmutable(), 'user'));
        $this->em->flush();
        self::getContainer()->get(TrashReceivedDocument::class)->trash($id, 'Duplicate');
        self::assertSame([], $this->shares());

        self::getContainer()->get(RestoreReceivedDocument::class)->restore($id);
        self::assertSame(['rate:21 own 100000 document 2026-11-01'], $this->shares());
    }

    public function testTheRecalculateCommandGivesTheDocumentsRecordedBeforeMarginTheirShares(): void
    {
        $id = $this->recording()->create($this->input(issue: '2026-11-24', taxPoint: '2026-11-24'))->document->id();
        $draft = $this->draft();
        // As if Margin had been installed afterwards: the document has no shares.
        $this->em->getConnection()->executeStatement('DELETE FROM margin_cost_share');
        $this->em->clear();
        self::assertSame([], $this->shares());

        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('margin:recalculate'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('Considered 1 documents.', $tester->getDisplay());
        self::assertSame(['rate:21 - 100000 unassigned 2026-11-01'], $this->shares());

        $tester->execute([]);
        self::assertSame(['rate:21 - 100000 unassigned 2026-11-01'], $this->shares(), 'A second run changes nothing.');
        self::assertSame([$id], iterator_to_array($this->costs()->documentIds(), false));
        self::assertNotContains($draft, iterator_to_array($this->costs()->documentIds(), false));
    }

    public function testEditingTheLinesCountsTheSharesAgainUnderTheKeptIds(): void
    {
        $id = $this->recording()->create($this->input(issue: '2026-11-24', taxPoint: '2026-11-24'))->document->id();
        $this->editing()->edit($id, $this->input(issue: '2026-11-24', taxPoint: '2026-11-24', lines: [
            new ReceivedDocumentLineInput('Hosting', Money::ofMinor(70_000, Currency::CZK), '21'),
            new ReceivedDocumentLineInput('Domain', Money::ofMinor(30_000, Currency::CZK), '21'),
        ]));

        $shares = $this->shares();

        $rows = array_map(static fn (string $row): string => explode(' ', $row, 2)[1], $shares);
        sort($rows);
        self::assertSame(['- 30000 unassigned 2026-11-01', '- 70000 unassigned 2026-11-01'], $rows);
    }

    public function testAForeignDocumentWithoutARateIsAKnownGap(): void
    {
        $id = $this->recording()->create($this->input(kind: ReceivedDocumentKind::ForeignInvoice, treatment: PurchaseTaxTreatment::EuService, currency: Currency::EUR, base: 10_000, rate: ExchangeRate::of('25')))->document->id();
        self::assertSame(['rate:21 - 250000 unassigned 2026-10-01'], $this->shares());

        // The rate is gone, as in a document recorded before it was published.
        $connection = $this->em->getConnection();
        $stored = $connection->fetchOne('SELECT vat_recap FROM received_document WHERE id = ?', [$id]);
        self::assertIsString($stored);
        $recap = json_decode($stored, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($recap);
        $recap = array_map(static fn (mixed $row): array => ['base_czk' => null] + (array) $row, $recap);
        $connection->executeStatement('UPDATE received_document SET exchange_rate = NULL, vat_recap = ? WHERE id = ?', [json_encode($recap, \JSON_THROW_ON_ERROR), $id]);
        $this->em->clear();
        self::getContainer()->get(CostShareCalculation::class)->recalculate($id);

        self::assertSame([], $this->shares());
        $this->recording()->create($this->input(supplierNumber: 'CZK'));
        self::assertSame([$id], new KnownGaps(new CostSources([$this->costs()]))->documentsMissingExchangeRate([$id]));
        self::assertSame([$id], [...new CostSources([$this->costs()])->documentIdsWithoutExchangeRate()], 'Only the document without a rate is on the short list, not the one in CZK.');
    }

    public function testTheDocumentsOfASupplierFromAMonthOn(): void
    {
        $october = $this->recording()->create($this->input(supplierNumber: 'OCT', issue: '2026-10-28', taxPoint: '2026-10-28'))->document->id();
        $november = $this->recording()->create($this->input(supplierNumber: 'NOV', issue: '2026-11-24', taxPoint: '2026-11-24'))->document->id();
        $trashed = $this->recording()->create($this->input(supplierNumber: 'TRASH', issue: '2026-11-25', taxPoint: '2026-11-25'))->document->id();
        self::getContainer()->get(TrashReceivedDocument::class)->trash($trashed, 'Duplicate');

        $ids = iterator_to_array($this->costs()->documentsOf($this->supplier->id(), new \DateTimeImmutable('2026-11-01')), false);

        self::assertSame([$november], $ids);
        self::assertContains($october, iterator_to_array($this->costs()->documentsOf($this->supplier->id(), new \DateTimeImmutable('2026-10-01')), false));
    }

    public function testTheDocumentsOfASupplierComeInTheOrderOfTheirIds(): void
    {
        $first = $this->recording()->create($this->input(supplierNumber: 'A', issue: '2026-11-24', taxPoint: '2026-11-24'))->document->id();
        $second = $this->recording()->create($this->input(supplierNumber: 'B', issue: '2026-11-25', taxPoint: '2026-11-25'))->document->id();
        // The one recorded first gets the higher id, so the order of ids is not that of recording.
        $high = 'ffffffff-ffff-7fff-8fff-ffffffffffff';
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM received_document_line WHERE document_id = ?', [$first]);
        $connection->executeStatement('UPDATE received_document SET id = ? WHERE id = ?', [$high, $first]);
        $this->em->clear();

        self::assertSame([$second, $high], iterator_to_array($this->costs()->documentsOf($this->supplier->id(), new \DateTimeImmutable('2026-11-01')), false));
    }

    public function testADocumentWithoutAnyDayIsNoCostDocument(): void
    {
        $id = $this->recording()->create($this->input(issue: '2026-11-24', taxPoint: null))->document->id();
        $this->em->getConnection()->executeStatement('UPDATE received_document SET issue_date = NULL, tax_point_date = NULL WHERE id = ?', [$id]);
        $this->em->clear();

        self::assertNull($this->costs()->document($id));
    }

    private function costs(): ReceivedDocumentCosts
    {
        return self::getContainer()->get(ReceivedDocumentCosts::class);
    }

    /**
     * @return list<string> "line recipient amount source month", sorted
     */
    private function shares(): array
    {
        $this->em->flush();
        $this->em->clear();
        $rows = array_map(static fn (CostShare $share): string => \sprintf('%s %s %d %s %s', $share->lineKey(), $share->recipient()?->toString() ?? '-', $share->amount()->minor(), $share->source()->value, $share->month()->format('Y-m-d')), $this->em->getRepository(CostShare::class)->findAll());
        sort($rows);

        return $rows;
    }
}
