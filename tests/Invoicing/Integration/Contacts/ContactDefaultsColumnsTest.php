<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Contacts;

use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Domain\PartyInvoicingDefault;
use Foresi\Invoicing\Integration\Contacts\ContactDefaultsColumns;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use PHPUnit\Framework\TestCase;

/**
 * The invoicing defaults as columns of the contact list, without the list around them: the web
 * tests of the list reach these lines too, but by hundreds and slowly.
 */
final class ContactDefaultsColumnsTest extends TestCase
{
    private const string WITHIN = PartyInvoicingDefault::class.' ipd WHERE ipd.party = p';

    public function testEachDefaultIsAHiddenColumnThatSortsAndFilters(): void
    {
        $columns = [];
        foreach ($this->columns(InvoicingDefaults::standard())->attributes() as $attribute) {
            $columns[$attribute->key] = [$attribute->label, $attribute->type, $attribute->sort, $attribute->filterExpression(), $attribute->defaultPosition, $attribute->defaultVisible, $attribute->phone, $attribute->filterable, $attribute->within];
        }

        $column = static fn (string $key, AttributeType $type, string $property, int $position): array => ['invoice.contact_defaults.column.'.$key, $type, 'ipd.invoicingDefaults.'.$property, 'ipd.invoicingDefaults.'.$property, $position, false, false, true, self::WITHIN];
        self::assertSame([
            'default_due_days' => $column('default_due_days', AttributeType::Number, 'dueDays', 170),
            'currency' => $column('currency', AttributeType::Text, 'currency', 180),
            'payment_method' => $column('payment_method', AttributeType::Enum, 'paymentMethod', 190),
            'language' => $column('language', AttributeType::Enum, 'language', 200),
            'reverse_charge' => $column('reverse_charge', AttributeType::Boolean, 'reverseCharge', 210),
            'reminders' => $column('reminders', AttributeType::Boolean, 'sendReminders', 220),
            'hourly_rate' => $column('hourly_rate', AttributeType::Money, 'hourlyRateMinor', 230),
        ], $columns);
    }

    public function testTheEnumsLabelEveryValue(): void
    {
        $attributes = $this->byKey($this->columns(InvoicingDefaults::standard()));

        self::assertSame([
            'bank_transfer' => 'invoice.contact_defaults.payment_method.bank_transfer',
            'cash' => 'invoice.contact_defaults.payment_method.cash',
            'card' => 'invoice.contact_defaults.payment_method.card',
        ], $attributes['payment_method']->options);
        self::assertSame([
            'cs' => 'invoice.contact_defaults.document_language.cs',
            'en' => 'invoice.contact_defaults.document_language.en',
        ], $attributes['language']->options);
    }

    public function testTheValuesComeFromTheLoadedPageAndElseFromTheContact(): void
    {
        $loaded = new InvoicingDefaults(30, Currency::EUR, DocumentLanguage::English, PaymentMethod::Card, true, false, Money::ofMinor(150000, Currency::EUR));
        $columns = $this->columns(InvoicingDefaults::standard(), ['loaded' => $loaded]);
        $columns->load(['loaded']);
        $attributes = $this->byKey($columns);

        $values = static fn (string $id): array => array_map(static fn (Attribute $attribute): mixed => $attribute->value($id), $attributes);
        self::assertEquals([
            'default_due_days' => 30, 'currency' => 'EUR', 'payment_method' => PaymentMethod::Card, 'language' => DocumentLanguage::English,
            'reverse_charge' => true, 'reminders' => false, 'hourly_rate' => Money::ofMinor(150000, Currency::EUR),
        ], $values('loaded'));
        self::assertEquals([
            'default_due_days' => 14, 'currency' => 'CZK', 'payment_method' => PaymentMethod::BankTransfer, 'language' => DocumentLanguage::Czech,
            'reverse_charge' => false, 'reminders' => true, 'hourly_rate' => null,
        ], $values('other'));
    }

    public function testTheListJoinsTheDefaultsOfEachContact(): void
    {
        self::assertSame('LEFT JOIN '.PartyInvoicingDefault::class.' ipd WITH ipd.party = p', $this->columns(InvoicingDefaults::standard())->join());
    }

    public function testANewRowHasTheDefaultsItIsGivenElseTheStandardOnes(): void
    {
        $party = new Party(PartyKind::Person, 'Example Customer', null, null, '');
        $defaults = new InvoicingDefaults(30, Currency::EUR, DocumentLanguage::English, PaymentMethod::Card, true);

        self::assertSame($defaults, new PartyInvoicingDefault($party, $defaults)->defaults());
        self::assertTrue(new PartyInvoicingDefault($party)->defaults()->equals(InvoicingDefaults::standard()));
    }

    /**
     * @param array<string, InvoicingDefaults> $page what ofParties() answers
     */
    private function columns(InvoicingDefaults $stored, array $page = []): ContactDefaultsColumns
    {
        $defaults = self::createStub(PartyInvoicingDefaults::class);
        $defaults->method('of')->willReturn($stored);
        $defaults->method('ofParties')->willReturn($page);

        return new ContactDefaultsColumns($defaults);
    }

    /**
     * @return array<string, Attribute>
     */
    private function byKey(ContactDefaultsColumns $columns): array
    {
        $attributes = [];
        foreach ($columns->attributes() as $attribute) {
            $attributes[$attribute->key] = $attribute;
        }

        return $attributes;
    }
}
