<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Invoicing\Application\ConflictRetry;
use App\Invoicing\Application\InvoiceFilter;
use App\Invoicing\Application\IssuedDocumentFactory;
use App\Invoicing\Application\UnknownInvoice;
use App\Invoicing\Domain\AbandonedNumber;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Invoicing\Application\InvoiceTestCase;
use Doctrine\DBAL\Driver\Exception as DriverFailure;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class InvoicePersistenceTest extends InvoiceTestCase
{
    public function testTheSnapshotsAndAmountsSurviveTheDatabase(): void
    {
        $this->firm->changeLogo('logo.png');
        $this->firm->changeStamp('stamp.png');
        $this->em->flush();

        $invoice = $this->reloaded($this->issue->issue($this->input([$this->line('121.00', PriceType::WithVat)])));
        $line = $this->store->lines($invoice)[0];

        self::assertSame('logo.png', $invoice->issuer()->logo);
        self::assertSame('stamp.png', $invoice->issuer()->stamp);
        self::assertSame('CZ', $invoice->issuer()->address->countryCode);
        self::assertSame(12100, $line->price()->minor());
        self::assertSame(10000, $line->unitNet()->minor());
        self::assertSame(10000, $line->net()->minor());
        self::assertSame(2100, $line->vat()->minor());
        self::assertSame(12100, $line->gross()->minor());
        self::assertSame(10000, $line->quantity()->tenThousandths());
        self::assertSame('0.00', $line->discount()->decimal());
        self::assertSame($invoice, $line->auditRoot());
    }

    public function testTheIssuedDocumentShowsWhatIsStored(): void
    {
        $invoice = $this->reloaded($this->issue->issue($this->input([$this->line('10.40'), $this->line('10.00', kind: \App\Invoicing\Domain\VatRateKind::Reduced)])));

        $document = self::getContainer()->get(IssuedDocumentFactory::class)->of($invoice);

        self::assertSame('20260001', $document->number);
        self::assertCount(2, $document->lines);
        self::assertCount(2, $document->summaries);
        self::assertSame('21.00', $document->summaries[0]->rate?->decimal());
        self::assertSame($invoice->gross()->minor(), $document->gross->minor());
        self::assertSame($invoice->payable()->minor(), $document->payable->minor());
        self::assertSame($invoice->totalRounding()->minor(), $document->rounding->minor());
        self::assertSame('Example Customer a.s.', $document->customer->name);
        self::assertSame('Example Firm s.r.o.', $document->issuer->name);
        self::assertSame($invoice->id(), $document->id);
    }

    public function testTheIssuedDocumentAddsUpTheStoredAmountsAndPricesNothing(): void
    {
        $invoice = $this->issue->issue($this->input([$this->line('100.00')]));
        // As a document issued under older rules would be stored.
        $this->em->getConnection()->update('invoice_line', ['vat_minor' => 2000, 'gross_minor' => 12000], ['invoice_id' => $invoice->id()]);

        $document = self::getContainer()->get(IssuedDocumentFactory::class)->of($this->reloaded($invoice));

        self::assertSame(2000, $document->summaries[0]->vat->minor());
        self::assertSame(12000, $document->summaries[0]->gross->minor());
    }

    public function testAnInvoiceOfAnotherTenantDoesNotExist(): void
    {
        $id = $this->issue->issue($this->input())->id();
        $this->em->clear();

        self::getContainer()->get(TenantContext::class)->switchTo(TenantId::generate());

        $this->expectException(UnknownInvoice::class);
        $this->store->get($id);
    }

    public function testTheNumberKeyIsUniquePerFirm(): void
    {
        $first = $this->issue->issue($this->input());
        $this->em->clear();

        $connection = $this->em->getConnection();
        $row = $connection->fetchAssociative('SELECT * FROM invoice WHERE id = ?', [$first->id()]);
        self::assertIsArray($row);
        $row['id'] = '01963c5e-0000-7000-8000-000000000001';

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->insert('invoice', $row);
    }

    public function testTheSameNumberIsFineInAnotherFirm(): void
    {
        $second = $this->secondFirm();
        $this->issue->issue($this->input());

        $invoice = $this->issue->issue($this->input(overrides: ['firmId' => $second->id(), 'seriesId' => null]));

        self::assertSame('20260001', $invoice->number());
    }

    public function testACustomerWithInvoicesCannotBeRemoved(): void
    {
        $this->issue->issue($this->input());
        $connection = $this->em->getConnection();
        $this->enforceForeignKeys();

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $connection->delete('party', ['id' => $this->customer->id()]);
    }

    public function testNumberTakenSeesInvoicesAbandonedNumbersAndNothingElse(): void
    {
        $invoice = $this->issue->issue($this->input());

        self::assertTrue($this->store->numberTaken($this->firm, '20260001', null));
        self::assertFalse($this->store->numberTaken($this->firm, '20260001', $invoice));
        self::assertFalse($this->store->numberTaken($this->firm, '20260002', null));
        self::assertFalse($this->store->numberTaken($this->secondFirm(), '20260001', null));

        $this->edited($invoice, $this->input(overrides: ['typedNumber' => '20260002']));

        self::assertTrue($this->store->numberTaken($this->firm, '20260001', null));
        self::assertTrue($this->store->numberTaken($this->firm, '20260001', $invoice), 'even its own invoice does not take it back');
        $abandoned = $this->em->getRepository(AbandonedNumber::class)->findOneBy([]);
        self::assertInstanceOf(AbandonedNumber::class, $abandoned);
        self::assertSame('20260001', $abandoned->number());
        self::assertSame($invoice->id(), $abandoned->auditRoot() instanceof Invoice ? $abandoned->auditRoot()->id() : null);
    }

    public function testAnInvoiceCountsForTheSeriesEvenWithoutACounterRow(): void
    {
        $series = new Series($this->firm, 'Second', SeriesFormat::fromString('B{NNN}'), DocumentKind::Invoice);
        $this->catalog->add($series);
        $this->em->flush();
        self::assertFalse($this->catalog->hasDocuments($series));

        $this->issue->issue($this->input(overrides: ['seriesId' => $series->id(), 'typedNumber' => 'SOMETHING-ELSE']));

        self::assertTrue($this->catalog->hasDocuments($this->catalog->get($series->id())));
    }

    public function testThePageListsNewestFirstAndFilters(): void
    {
        $first = $this->issue->issue($this->input(overrides: ['issueDate' => new \DateTimeImmutable('2026-11-01')]));
        $second = $this->issue->issue($this->input(overrides: ['issueDate' => new \DateTimeImmutable('2026-11-02')]));
        $trashed = $this->issue->issue($this->input(overrides: ['issueDate' => new \DateTimeImmutable('2026-11-03')]));
        $this->trash->trash($trashed, 'Wrong customer');
        $other = $this->secondFirm();
        $foreign = $this->issue->issue($this->input(overrides: ['firmId' => $other->id(), 'seriesId' => null]));

        $page = $this->store->page(new InvoiceFilter(null, false, 1));
        self::assertSame([$second->id(), $foreign->id(), $first->id()], array_map(static fn (\App\Invoicing\Application\InvoiceRow $row): string => $row->id, $page->rows));
        self::assertSame(3, $page->total);
        self::assertSame(1, $page->page);
        self::assertSame(1, $page->pages);

        $row = $page->rows[0];
        self::assertSame('20260002', $row->number);
        self::assertSame('Example Firm s.r.o.', $row->firmName);
        self::assertSame('Example Customer a.s.', $row->customerName);
        self::assertSame('2026-11-02', $row->issueDate->format('Y-m-d'));
        self::assertSame(121000, $row->payable->minor());
        self::assertFalse($row->delivered);
        self::assertFalse($row->trashed);

        self::assertSame([$foreign->id()], array_map(static fn (\App\Invoicing\Application\InvoiceRow $row): string => $row->id, $this->store->page(new InvoiceFilter($other->id(), false, 1))->rows));
        self::assertSame([$trashed->id()], array_map(static fn (\App\Invoicing\Application\InvoiceRow $row): string => $row->id, $this->store->page(new InvoiceFilter(null, true, 1))->rows));
    }

    public function testThePageStaysInRange(): void
    {
        $this->issue->issue($this->input());

        self::assertSame(1, $this->store->page(new InvoiceFilter(null, false, 99))->page);
        self::assertSame(1, $this->store->page(new InvoiceFilter(null, false, -1))->page);
    }

    public function testTheLastFirmOfAUser(): void
    {
        $user = '01963c5e-0000-7000-8000-00000000000a';
        $none = $this->store->lastFirmOf($user);
        self::assertNull($none);

        $connection = $this->em->getConnection();
        $invoice = $this->issue->issue($this->input());
        $connection->update('invoice', ['created_by' => $user], ['id' => $invoice->id()]);
        $last = $this->store->lastFirmOf($user);
        self::assertSame($this->firm->id(), $last?->id());

        $this->firm->archive(new \DateTimeImmutable('2026-11-02'));
        $this->em->flush();
        $archived = $this->store->lastFirmOf($user);
        self::assertNull($archived, 'an archived firm issues nothing');
    }

    public function testALockErrorRunsTheWholeUnitAgainUpToThreeTimes(): void
    {
        $retry = self::getContainer()->get(ConflictRetry::class);
        $attempts = 0;

        $result = $retry->run(function () use (&$attempts): int {
            if (++$attempts < 4) {
                throw $this->deadlock();
            }

            return $attempts;
        });

        self::assertSame(4, $result);
    }

    public function testALockErrorTravelsOnAfterThreeRetries(): void
    {
        $retry = self::getContainer()->get(ConflictRetry::class);
        $attempts = 0;

        try {
            $retry->run(function () use (&$attempts): int {
                if (++$attempts < 100) {
                    throw $this->deadlock();
                }

                return $attempts;
            });
            self::fail('Expected the lock error.');
        } catch (DeadlockException) {
            self::assertSame(4, $attempts);
        }
    }

    public function testOtherFailuresAreNotRetried(): void
    {
        $retry = self::getContainer()->get(ConflictRetry::class);
        $attempts = 0;

        try {
            $retry->run(static function () use (&$attempts): int {
                if (++$attempts < 100) {
                    throw new \RuntimeException('Broken.');
                }

                return $attempts;
            });
            self::fail('Expected the failure.');
        } catch (\RuntimeException) {
            self::assertSame(1, $attempts);
        }
    }

    public function testAUniqueViolationRunsTheUnitAgain(): void
    {
        $retry = self::getContainer()->get(ConflictRetry::class);
        $attempts = 0;

        $retry->run(function () use (&$attempts): void {
            if (1 === ++$attempts) {
                throw new UniqueConstraintViolationException($this->driverFailure(), null);
            }
        });

        self::assertSame(2, $attempts);
    }

    private function deadlock(): DeadlockException
    {
        return new DeadlockException($this->driverFailure(), null);
    }

    private function driverFailure(): DriverFailure
    {
        return new class('Deadlock.') extends \RuntimeException implements DriverFailure {
            public function getSQLState(): ?string
            {
                return null;
            }
        };
    }

    private function secondFirm(): LegalEntity
    {
        $firm = new LegalEntity('Second Firm s.r.o.', new RegistrationNumber('CZ', '27074358'), VatId::fromString('CZ27074358'), $this->firm->address(), VatStatus::Registered, 'Register', [self::IBAN]);
        $this->em->persist($firm);
        $this->em->flush();

        return $firm;
    }

    private function enforceForeignKeys(): void
    {
        $connection = $this->em->getConnection();
        if (str_contains(strtolower($connection->getDatabasePlatform()::class), 'sqlite')) {
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        }
    }
}
