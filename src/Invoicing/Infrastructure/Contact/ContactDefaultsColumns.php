<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Contact;

use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Invoicing\Domain\PartyInvoicingDefault;
use Foresi\Party\Contract\ContactColumns;
use Foresi\Party\Contract\Contacts;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;

/**
 * The invoicing defaults as columns of the contact list, hidden until a person
 * adds them, each one a sort and a filter of the list's views.
 */
final class ContactDefaultsColumns implements ContactColumns
{
    private const string ROW = 'ipd';

    /** @var array<string, InvoicingDefaults> the defaults of the page by contact id */
    private array $page = [];

    public function __construct(private readonly PartyInvoicingDefaults $defaults)
    {
    }

    public function attributes(): iterable
    {
        return [
            $this->column('default_due_days', 'dueDays', AttributeType::Number, static fn (InvoicingDefaults $values): int => $values->dueDays, 170),
            $this->column('currency', 'currency', AttributeType::Text, static fn (InvoicingDefaults $values): string => $values->currency->value, 180),
            $this->column('payment_method', 'paymentMethod', AttributeType::Enum, static fn (InvoicingDefaults $values): PaymentMethod => $values->paymentMethod, 190, self::options(PaymentMethod::cases(), 'invoice.contact_defaults.payment_method.')),
            $this->column('language', 'language', AttributeType::Enum, static fn (InvoicingDefaults $values): DocumentLanguage => $values->language, 200, self::options(DocumentLanguage::cases(), 'invoice.contact_defaults.document_language.')),
            $this->column('reverse_charge', 'reverseCharge', AttributeType::Boolean, static fn (InvoicingDefaults $values): bool => $values->reverseCharge, 210),
            $this->column('reminders', 'sendReminders', AttributeType::Boolean, static fn (InvoicingDefaults $values): bool => $values->sendReminders, 220),
            $this->column('hourly_rate', 'hourlyRateMinor', AttributeType::Money, static fn (InvoicingDefaults $values): ?Money => $values->hourlyRate(), 230),
        ];
    }

    /**
     * @param \Closure(InvoicingDefaults): mixed $value
     * @param array<string, string>              $options
     */
    private function column(string $key, string $property, AttributeType $type, \Closure $value, int $position, array $options = []): Attribute
    {
        $field = self::ROW.'.invoicingDefaults.'.$property;

        return new Attribute(
            $key,
            'invoice.contact_defaults.column.'.$key,
            $type,
            fn (string $contactId): mixed => $value($this->defaultsOf($contactId)),
            sort: $field,
            defaultPosition: $position,
            options: $options,
            phone: false,
            filterable: true,
            filter: $field,
            within: PartyInvoicingDefault::class.' '.self::ROW.' WHERE '.self::ROW.'.party = '.Contacts::ALIAS,
        );
    }

    public function join(): string
    {
        return 'LEFT JOIN '.PartyInvoicingDefault::class.' '.self::ROW.' WITH '.self::ROW.'.party = '.Contacts::ALIAS;
    }

    public function load(array $contactIds): void
    {
        $this->page = $this->defaults->ofParties($contactIds);
    }

    private function defaultsOf(string $contactId): InvoicingDefaults
    {
        return $this->page[$contactId] ?? $this->defaults->of($contactId);
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, string>
     */
    private static function options(array $cases, string $prefix): array
    {
        $options = [];
        foreach ($cases as $case) {
            $options[(string) $case->value] = $prefix.$case->value;
        }

        return $options;
    }
}
