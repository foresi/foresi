<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Contacts;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Contacts\Contract\ContactImportColumn;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Integration\Contacts\ContactDefaultsImport;
use Foresi\Shared\Domain\Currencies;
use Foresi\Shared\Domain\Currency;
use PHPUnit\Framework\TestCase;

final class ContactDefaultsImportTest extends TestCase
{
    private const string CONTACT = '00000000-0000-4000-8000-000000000001';

    private ?InvoicingDefaults $written = null;

    public function testTheDuePeriodIsBetweenZeroAndAYear(): void
    {
        $import = $this->import(InvoicingDefaults::standard());

        self::assertSame([], $import->check(['due_days' => 0]));
        self::assertSame([], $import->check(['due_days' => 365]));
        self::assertSame(['due_days' => ['import.error.out_of_range', ['value' => -1, 'min' => 0, 'max' => 365]]], $import->check(['due_days' => -1]));
        self::assertSame(['due_days' => ['import.error.out_of_range', ['value' => 366, 'min' => 0, 'max' => 365]]], $import->check(['due_days' => 366]));
    }

    public function testARowSetsWhatItSaysAndKeepsTheRest(): void
    {
        $base = new InvoicingDefaults(30, Currency::of('EUR'), InvoicingDefaults::standard()->language, PaymentMethod::Cash, true);

        $this->import($base)->write(self::CONTACT, ['display_name' => 'Acme', 'due_days' => 7, 'currency' => 'USD'], false, false);

        self::assertNotNull($this->written);
        self::assertSame(7, $this->written->dueDays);
        self::assertSame(Currency::of('USD'), $this->written->currency);
        self::assertSame(PaymentMethod::Cash, $this->written->paymentMethod);
        self::assertTrue($this->written->reverseCharge);
    }

    public function testEmptyCellsClearToTheStandardOnlyWhenAsked(): void
    {
        $base = new InvoicingDefaults(30, Currency::of('EUR'), InvoicingDefaults::standard()->language, PaymentMethod::Cash, true);
        $empty = ['display_name' => 'Acme', 'due_days' => null, 'currency' => null, 'payment_method' => null];

        $this->import($base)->write(self::CONTACT, $empty, false, false);
        self::assertNull($this->written, 'kept cells change nothing');

        $this->import($base)->write(self::CONTACT, $empty, true, false);
        self::assertNotNull($this->written);
        self::assertTrue($this->written->equals(new InvoicingDefaults(14, Currency::of('CZK'), $base->language, PaymentMethod::BankTransfer, true)));
    }

    public function testTheColumnsAreFoundByTheNamesTheyUsuallyHave(): void
    {
        $columns = array_map(static fn (ContactImportColumn $column): array => [$column->key, $column->aliases, $column->choices], $this->import(InvoicingDefaults::standard())->columns());

        self::assertSame([
            ['due_days', ['splatnost', 'due days'], []],
            ['currency', ['mena', 'currency'], Currencies::currentCodes()],
            ['payment_method', ['zpusob platby', 'forma uhrady', 'payment method'], ['bank_transfer', 'cash', 'card']],
        ], $columns);
    }

    public function testAPaymentMethodGivenWinsOverTheOneKept(): void
    {
        $base = new InvoicingDefaults(30, Currency::of('EUR'), InvoicingDefaults::standard()->language, PaymentMethod::Cash, true);

        $this->import($base)->write(self::CONTACT, ['payment_method' => 'card'], true, false);

        self::assertSame(PaymentMethod::Card, $this->written?->paymentMethod);
    }

    public function testAChangeIsWrittenAtOnce(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $defaults = self::createStub(PartyInvoicingDefaults::class);
        $defaults->method('of')->willReturn(InvoicingDefaults::standard());

        new ContactDefaultsImport($defaults, $em)->write(self::CONTACT, ['due_days' => 30], false, false);
    }

    public function testAnUndoPutsBackWhatTheSnapshotHeld(): void
    {
        $before = new InvoicingDefaults(21, Currency::of('EUR'), DocumentLanguage::English, PaymentMethod::Card, true, false);
        $snapshot = $this->import($before)->snapshot(self::CONTACT);

        $this->import(InvoicingDefaults::standard())->restore(self::CONTACT, $snapshot);

        self::assertNotNull($this->written);
        self::assertTrue($this->written->equals($before));
    }

    private function import(InvoicingDefaults $stored): ContactDefaultsImport
    {
        $this->written = null;
        $defaults = self::createStub(PartyInvoicingDefaults::class);
        $defaults->method('of')->willReturn($stored);
        $defaults->method('change')->willReturnCallback(function (string $id, InvoicingDefaults $changed): void {
            $this->written = $changed;
        });

        return new ContactDefaultsImport($defaults, self::createStub(EntityManagerInterface::class));
    }
}
