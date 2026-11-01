<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Audit\Application\AuditActor;
use App\Invoicing\Application\ConflictRetry;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\DocumentTotals;
use App\Invoicing\Domain\LineAmounts;
use App\Invoicing\Domain\LineValues;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class TrashInvoiceTest extends InvoiceTestCase
{
    use ClockSensitiveTrait;

    public function testTrashRecordsWhoWhenAndWhy(): void
    {
        self::mockTime('2026-11-03 10:00:00');
        $invoice = $this->issue->issue($this->input());

        $this->trash->trash($invoice->id(), '  Wrong customer ');
        $invoice = $this->reloaded($invoice);

        self::assertTrue($invoice->isTrashed());
        self::assertSame('Wrong customer', $invoice->deletedReason());
        self::assertSame('2026-11-03', $invoice->deletedAt()?->format('Y-m-d'));
        self::assertNull($invoice->deletedBy());
        self::assertSame('20260001', $invoice->number());
    }

    public function testTheAggregateRecordsWhoDeletedIt(): void
    {
        $invoice = $this->issue->issue($this->input());

        $invoice->trash(new \DateTimeImmutable('2026-11-03'), '01963c5e-0000-7000-8000-00000000000a', 'Wrong customer');

        self::assertSame('01963c5e-0000-7000-8000-00000000000a', $invoice->deletedBy());
    }

    public function testADeliveredInvoiceCannotBeTrashed(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->delivered->mark($invoice->id());

        $this->expectException(\DomainException::class);
        $this->trash->trash($invoice->id(), 'Wrong customer');
    }

    public function testTrashNeedsAReasonAndRestoreKeepsTheNumber(): void
    {
        $invoice = $this->issue->issue($this->input());

        try {
            $this->trash->trash($invoice->id(), '   ');
            self::fail('Expected a refusal.');
        } catch (\DomainException) {
            self::assertFalse($invoice->isTrashed());
        }

        $this->trash->trash($invoice->id(), 'Wrong customer');
        $this->trash->restore($invoice->id());
        $invoice = $this->reloaded($invoice);

        self::assertFalse($invoice->isTrashed());
        self::assertNull($invoice->deletedAt());
        self::assertNull($invoice->deletedBy());
        self::assertNull($invoice->deletedReason());
        self::assertSame('20260001', $invoice->number());
    }

    public function testEveryServiceReturnsTheInvoiceAsSaved(): void
    {
        $id = $this->issue->issue($this->input())->id();

        self::assertTrue($this->trash->trash($id, 'Wrong customer')->isTrashed());
        self::assertFalse($this->trash->restore($id)->isTrashed());
        self::assertTrue($this->delivered->mark($id)->isDelivered());
    }

    public function testAnInvoiceIsTrashedOnce(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice->id(), 'First reason');

        $this->expectException(\DomainException::class);
        $this->trash->trash($invoice->id(), 'Second reason');
    }

    public function testATrashedInvoiceCannotBeDelivered(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice->id(), 'Wrong customer');

        $this->expectException(\DomainException::class);
        $this->delivered->mark($invoice->id());
    }

    public function testDeliveryIsRecordedOnce(): void
    {
        self::mockTime('2026-11-03 10:00:00');
        $invoice = $this->issue->issue($this->input());
        $this->delivered->mark($invoice->id());
        self::mockTime('2026-11-20 10:00:00');
        $this->delivered->mark($invoice->id());

        self::assertTrue($this->reloaded($invoice)->isDelivered());
        self::assertSame('2026-11-03', $this->reloaded($invoice)->deliveredAt()?->format('Y-m-d'));
    }

    public function testTheServiceRecordsWhoDeletedIt(): void
    {
        $invoice = $this->issue->issue($this->input());
        $container = self::getContainer();
        $actor = new class implements AuditActor {
            public function userId(): string
            {
                return '01963c5e-0000-7000-8000-00000000000a';
            }
        };
        $trash = new TrashInvoice($this->store, $container->get(TransactionalWork::class), $container->get(ConflictRetry::class), $actor, new MockClock('2026-11-03'));

        $trash->trash($invoice->id(), 'Wrong customer');

        self::assertSame('01963c5e-0000-7000-8000-00000000000a', $this->reloaded($invoice)->deletedBy());
    }

    public function testAnInvoiceRestoresInAnArchivedFirm(): void
    {
        $invoice = $this->issue->issue($this->input());
        $this->trash->trash($invoice->id(), 'Wrong customer');
        $firm = $this->em->getRepository(LegalEntity::class)->find($this->firm->id());
        self::assertInstanceOf(LegalEntity::class, $firm);
        $firm->archive(new \DateTimeImmutable('2026-11-04'));
        $this->em->flush();

        $this->trash->restore($invoice->id());

        self::assertFalse($this->reloaded($invoice)->isTrashed());
    }

    public function testRestoringWhatIsNotInTheTrashChangesNothing(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->trash->restore($invoice->id());

        self::assertSame(1, $this->reloaded($invoice)->version());
    }

    public function testATrashedInvoiceRefusesEveryChangeOnTheAggregate(): void
    {
        $invoice = $this->issue->issue($this->input());
        $line = $this->store->lines($invoice)[0];
        $totals = new DocumentTotals([], $invoice->net(), $invoice->vat(), $invoice->gross(), $invoice->totalRounding(), $invoice->payable());
        $series = $invoice->series();
        $invoice->trash(new \DateTimeImmutable('2026-11-03'), null, 'Wrong customer');
        $values = new LineValues($line->price(), $line->quantity(), $line->priceType(), $line->treatment(), $line->rate(), $line->discount());
        $amounts = new LineAmounts($line->unitNet(), $line->net(), $line->vat(), $line->gross());
        $customerParty = $invoice->customerParty();
        $change = $this->input()->change($customerParty, $invoice->customer());
        $tries = [
            'change' => static fn () => $invoice->change($change),
            'totals' => static fn () => $invoice->assignTotals($totals),
            'number' => static fn (): ?\App\Invoicing\Domain\AbandonedNumber => $invoice->changeNumber($series, 'X-1'),
            'delivered' => static fn () => $invoice->markDelivered(new \DateTimeImmutable('2026-11-04')),
            'trash' => static fn () => $invoice->trash(new \DateTimeImmutable('2026-11-04'), null, 'Again'),
            'line' => static fn () => $line->revise('Changed', 'h', null, null, $values, $amounts),
        ];
        foreach ($tries as $name => $try) {
            try {
                $try();
                self::fail('Expected a refusal of '.$name.'.');
            } catch (\DomainException $refused) {
                self::assertStringContainsString('in the trash', $refused->getMessage(), $name);
            }
        }

        self::assertSame('Wrong customer', $invoice->deletedReason());
        $invoice->restore();
        self::assertSame('20260001', $invoice->number());
    }

    public function testADeliveredInvoiceRefusesTrashAndRenumberOnTheAggregate(): void
    {
        $invoice = $this->issue->issue($this->input());
        $invoice->markDelivered(new \DateTimeImmutable('2026-11-03'));
        $series = $invoice->series();

        try {
            $invoice->trash(new \DateTimeImmutable('2026-11-04'), null, 'Wrong customer');
            self::fail('Expected a refusal.');
        } catch (\DomainException $refused) {
            self::assertSame('A delivered document cannot be deleted.', $refused->getMessage());
        }
        $this->expectExceptionMessageIsOrContains('Series and number are fixed once the document is delivered.');
        $invoice->changeNumber($series, 'X-1');
    }

    public function testASeriesOfAnotherFirmIsRefusedOnTheAggregate(): void
    {
        $invoice = $this->issue->issue($this->input());
        $other = new LegalEntity('Second Firm s.r.o.', new RegistrationNumber('CZ', '27074358'), null, $this->firm->address(), VatStatus::NotRegistered, 'Register', []);
        $this->em->persist($other);
        $series = $this->seriesOf($other);

        $this->expectExceptionMessageIsOrContains('A document takes a series of its own firm.');
        $invoice->changeNumber($series, 'X-1');
    }

    public function testTheReasonIsRequiredOnTheAggregate(): void
    {
        $invoice = $this->issue->issue($this->input());

        $this->expectExceptionMessageIsOrContains('Deleting a document needs a reason.');
        $invoice->trash(new \DateTimeImmutable('2026-11-04'), null, '  ');
    }
}
