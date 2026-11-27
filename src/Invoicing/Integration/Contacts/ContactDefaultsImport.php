<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Integration\Contacts;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Contacts\Contract\ContactImportColumn;
use Foresi\Contacts\Contract\ContactImportColumns;
use Foresi\Invoicing\Contract\DocumentLanguage;
use Foresi\Invoicing\Contract\InvoicingDefaults;
use Foresi\Invoicing\Contract\PartyInvoicingDefaults;
use Foresi\Invoicing\Contract\PaymentMethod;
use Foresi\Shared\Domain\Currency;

/**
 * The due period, the currency and the payment method of a contact in the
 * import and the export of contacts. An import never sets the language, the
 * reverse charge, the reminders or the hourly rate, so it keeps them; its undo
 * puts back what it changed.
 */
final readonly class ContactDefaultsImport implements ContactImportColumns
{
    private const int MAX_DUE_DAYS = 365;

    public function __construct(private PartyInvoicingDefaults $defaults, private EntityManagerInterface $em)
    {
    }

    public function key(): string
    {
        return 'invoicing';
    }

    public function columns(): array
    {
        return [
            new ContactImportColumn('due_days', 'import.field.party.due_days', 'integer', ['splatnost', 'due days']),
            new ContactImportColumn('currency', 'import.field.party.currency', 'choice', ['mena', 'currency'], array_map(static fn (Currency $currency): string => $currency->value, Currency::cases())),
            new ContactImportColumn('payment_method', 'import.field.party.payment_method', 'choice', ['zpusob platby', 'forma uhrady', 'payment method'], array_map(static fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases())),
        ];
    }

    public function check(array $values): array
    {
        $dueDays = $values['due_days'] ?? null;
        if (\is_int($dueDays) && ($dueDays < 0 || $dueDays > self::MAX_DUE_DAYS)) {
            return ['due_days' => ['import.error.out_of_range', ['value' => $dueDays, 'min' => 0, 'max' => self::MAX_DUE_DAYS]]];
        }

        return [];
    }

    public function write(string $contactId, array $values, bool $clear, bool $created): void
    {
        $base = $this->defaults->of($contactId);
        $standard = InvoicingDefaults::standard();
        $blank = static fn (string $key): bool => $clear && \array_key_exists($key, $values) && null === $values[$key];
        $dueDays = $values['due_days'] ?? null;
        $currency = Currency::tryFrom(\is_string($values['currency'] ?? null) ? $values['currency'] : '');
        $method = PaymentMethod::tryFrom(\is_string($values['payment_method'] ?? null) ? $values['payment_method'] : '');
        $defaults = new InvoicingDefaults(
            \is_int($dueDays) ? $dueDays : ($blank('due_days') ? $standard->dueDays : $base->dueDays),
            $currency ?? ($blank('currency') ? $standard->currency : $base->currency),
            $base->language,
            $method ?? ($blank('payment_method') ? $standard->paymentMethod : $base->paymentMethod),
            $base->reverseCharge,
            $base->sendReminders,
            $base->hourlyRate(),
        );
        if ($defaults->equals($base)) {
            return;
        }
        $this->defaults->change($contactId, $defaults);
        $this->em->flush();
    }

    public function snapshot(string $contactId): array
    {
        $defaults = $this->defaults->of($contactId);

        // The names an undo has always read, when the defaults were columns of the party.
        return [
            'dueDays' => $defaults->dueDays,
            'currency' => $defaults->currency->value,
            'language' => $defaults->language->value,
            'paymentMethod' => $defaults->paymentMethod->value,
            'reverseCharge' => $defaults->reverseCharge,
            'sendReminders' => $defaults->sendReminders,
        ];
    }

    public function restore(string $contactId, array $snapshot): void
    {
        $standard = InvoicingDefaults::standard();
        $text = static fn (string $key): string => \is_string($snapshot[$key] ?? null) ? $snapshot[$key] : '';
        $this->defaults->change($contactId, new InvoicingDefaults(
            \is_int($snapshot['dueDays'] ?? null) ? $snapshot['dueDays'] : $standard->dueDays,
            Currency::tryFrom($text('currency')) ?? $standard->currency,
            DocumentLanguage::tryFrom($text('language')) ?? $standard->language,
            PaymentMethod::tryFrom($text('paymentMethod')) ?? $standard->paymentMethod,
            true === ($snapshot['reverseCharge'] ?? null),
            false !== ($snapshot['sendReminders'] ?? null),
            // An import never sets the hourly rate, so the undo leaves it as it is.
            $this->defaults->of($contactId)->hourlyRate(),
        ));
    }

    public function export(string $contactId): array
    {
        $defaults = $this->defaults->of($contactId);

        return [
            'due_days' => (string) $defaults->dueDays,
            'currency' => $defaults->currency->value,
            'payment_method' => $defaults->paymentMethod->value,
        ];
    }
}
