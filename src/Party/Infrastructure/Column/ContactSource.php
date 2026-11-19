<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Column;

use Foresi\Instance\Application\InstanceClock;
use Foresi\Party\Application\PartyRow;
use Foresi\Party\Contract\Contacts;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\InvoicingDefaults;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistryResult;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Contract\Attribute;
use Foresi\Ui\Contract\DataSource;
use Foresi\Ui\Contract\RelationTarget;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The data source of the contact list (spec DS3): each value is read from one PartyRow, the
 * contact it was read from and its billing address, so a column added to the list costs no
 * query; each sort is a DQL expression over the contact "p".
 *
 * Every attribute with an expression is a filter field of the list's views too (D4): the e-mail
 * and the billing address are read in a subquery of the contact's addresses.
 *
 * Another list relates to a contact in its row "p" (RelationTarget), such as an invoice's customer.
 *
 * The name and the e-mail give way, cut short with their full text in a title. A phone shows the
 * name and the state; the rest goes in the line under the name.
 */
final readonly class ContactSource implements DataSource, RelationTarget
{
    public const string LIST = 'party.contacts';

    /** Every contact has a status: active unless archived or an unreliable VAT payer. */
    private const array STATUSES = ['active' => 'party.active', 'unreliable' => 'registry.result.unreliable', 'archived' => 'party.archived'];

    /** A contact that can be invoiced is green, a payer the register flags red; archived is neutral. */
    private const array STATUS_TONES = ['active' => 'green', 'unreliable' => 'red'];

    /** A role is what the firm does with the contact, green as the contact's page has shown it. */
    private const array ROLE_TONES = ['customer' => 'green', 'supplier' => 'green', 'partner' => 'green', 'authority' => 'green'];

    /** The billing address of the contact "p", which the filters of its city and country read in. */
    private const string BILLING = PartyAddress::class.' a WHERE a.party = p AND a.type = \'billing\'';

    public function __construct(private TranslatorInterface $translator, private InstanceClock $instanceClock)
    {
    }

    public function root(string $list): ?string
    {
        return self::LIST === $list ? Party::class.' '.Contacts::ALIAS : null;
    }

    public function attributes(string $list): iterable
    {
        if (self::LIST !== $list) {
            return [];
        }
        $label = static fn (string $key): string => 'party.column.'.$key;
        $party = static fn (\Closure $read): \Closure => static fn (PartyRow $row): mixed => $row->party instanceof Party ? $read($row->party) : null;
        $defaults = static fn (\Closure $read): \Closure => $party(static fn (Party $contact): mixed => $read($contact->invoicingDefaults()));
        $billing = static fn (\Closure $read): \Closure => static fn (PartyRow $row): mixed => $row->billing instanceof Address ? $read($row->billing) : null;

        return [
            // Shown by default, as the list has always shown them.
            new Attribute('name', $label('name'), AttributeType::Text, static fn (PartyRow $row): string => $row->displayName, sort: 'LOWER(p.displayName)', defaultVisible: true, defaultPosition: 10, locked: true, truncate: true, filterable: true, filter: 'p.displayName'),
            new Attribute('kind', $label('kind'), AttributeType::Enum, static fn (PartyRow $row): PartyKind => $row->kind, sort: 'p.kind', defaultVisible: true, defaultPosition: 20, options: $this->options(PartyKind::cases(), 'party.kind.'), phone: false, filterable: true),
            new Attribute('roles', $label('roles'), AttributeType::MultiEnum, static fn (PartyRow $row): array => $row->roles, defaultVisible: true, defaultPosition: 30, options: $this->options(PartyRole::cases(), 'party.role.'), aliases: ['role'], phone: false, filterable: true, filter: 'p.roles', tones: self::ROLE_TONES),
            new Attribute('registration_number', $label('registration_number'), AttributeType::Text, static fn (PartyRow $row): ?string => $row->registrationNumber, sort: 'p.registrationKey', defaultVisible: true, defaultPosition: 40, aliases: ['registration'], phone: false, filterable: true, filter: 'p.registrationNumber'),
            new Attribute('vat_id', $label('vat_id'), AttributeType::Text, static fn (PartyRow $row): ?string => $row->vatId, sort: 'p.vatId', defaultVisible: true, defaultPosition: 50, aliases: ['vat'], phone: false, filterable: true),
            // The list has shown the address as text, in a row that opens the contact.
            new Attribute('email', $label('email'), AttributeType::Email, static fn (PartyRow $row): ?string => $row->email, defaultVisible: true, defaultPosition: 60, cell: static fn (PartyRow $row): ?string => $row->email, phone: false, truncate: true, filterable: true, filter: 'e.address', within: PartyEmail::class.' e WHERE e.party = p'),
            new Attribute('status', $label('status'), AttributeType::Enum, static fn (PartyRow $row): string => self::statusOf($row), defaultVisible: true, defaultPosition: 70, options: self::STATUSES, tones: self::STATUS_TONES),
            // What a person can add (DS3), in the order of the contact's page.
            new Attribute('phone', $label('phone'), AttributeType::Phone, static fn (PartyRow $row): string => $row->phone, sort: 'p.phone', defaultPosition: 110, phone: false, filterable: true),
            new Attribute('mobile', $label('mobile'), AttributeType::Phone, $party(static fn (Party $contact): string => $contact->mobile()), sort: 'p.mobile', defaultPosition: 120, phone: false, filterable: true),
            new Attribute('website', $label('website'), AttributeType::Url, $party(static fn (Party $contact): string => $contact->website()), sort: 'LOWER(p.website)', defaultPosition: 130, phone: false, truncate: true, filterable: true, filter: 'p.website'),
            new Attribute('registration_country', $label('registration_country'), AttributeType::Country, $party(static fn (Party $contact): ?string => $contact->registrationNumber()?->countryCode), sort: 'p.registrationCountry', defaultPosition: 140, phone: false, filterable: true),
            new Attribute('billing_city', $label('billing_city'), AttributeType::Text, $billing(static fn (Address $address): string => $address->city), defaultPosition: 150, phone: false, truncate: true, filterable: true, filter: 'a.address.city', within: self::BILLING),
            new Attribute('billing_country', $label('billing_country'), AttributeType::Country, $billing(static fn (Address $address): string => $address->countryCode), defaultPosition: 160, phone: false, filterable: true, filter: 'a.address.countryCode', within: self::BILLING),
            new Attribute('default_due_days', $label('default_due_days'), AttributeType::Number, $defaults(static fn (InvoicingDefaults $values): int => $values->dueDays), sort: 'p.invoicingDefaults.dueDays', defaultPosition: 170, phone: false, filterable: true),
            new Attribute('currency', $label('currency'), AttributeType::Text, $defaults(static fn (InvoicingDefaults $values): string => $values->currency->value), sort: 'p.invoicingDefaults.currency', defaultPosition: 180, phone: false, filterable: true),
            new Attribute('payment_method', $label('payment_method'), AttributeType::Enum, $defaults(static fn (InvoicingDefaults $values): PaymentMethod => $values->paymentMethod), sort: 'p.invoicingDefaults.paymentMethod', defaultPosition: 190, options: $this->options(PaymentMethod::cases(), 'party.payment_method.'), phone: false, filterable: true),
            new Attribute('language', $label('language'), AttributeType::Enum, $defaults(static fn (InvoicingDefaults $values): DocumentLanguage => $values->language), sort: 'p.invoicingDefaults.language', defaultPosition: 200, options: $this->options(DocumentLanguage::cases(), 'party.language.'), phone: false, filterable: true),
            new Attribute('reverse_charge', $label('reverse_charge'), AttributeType::Boolean, $defaults(static fn (InvoicingDefaults $values): bool => $values->reverseCharge), sort: 'p.invoicingDefaults.reverseCharge', defaultPosition: 210, phone: false, filterable: true),
            new Attribute('reminders', $label('reminders'), AttributeType::Boolean, $defaults(static fn (InvoicingDefaults $values): bool => $values->sendReminders), sort: 'p.invoicingDefaults.sendReminders', defaultPosition: 220, phone: false, filterable: true),
            new Attribute('hourly_rate', $label('hourly_rate'), AttributeType::Money, $defaults(static fn (InvoicingDefaults $values): ?Money => $values->hourlyRate()), sort: 'p.invoicingDefaults.hourlyRateMinor', defaultPosition: 230, phone: false, filterable: true),
            new Attribute('vies_result', $label('vies_result'), AttributeType::Enum, $party(static fn (Party $contact): ?RegistryResult => $contact->viesCheck()?->result), sort: 'p.viesResult', defaultPosition: 240, options: $this->options(RegistryResult::cases(), 'registry.result.'), phone: false, filterable: true),
            new Attribute('unreliable_payer', $label('unreliable_payer'), AttributeType::Enum, $party(static fn (Party $contact): ?RegistryResult => $contact->unreliablePayerCheck()?->result), sort: 'p.unreliablePayerResult', defaultPosition: 250, options: $this->options(RegistryResult::cases(), 'registry.result.'), phone: false),
            new Attribute('archived_at', $label('archived_at'), AttributeType::DateTime, $party(fn (Party $contact): ?\DateTimeImmutable => $contact->archivedAt()?->setTimezone($this->instanceClock->timezone())), sort: 'p.archivedAt', defaultPosition: 260, phone: false, filterable: true),
        ];
    }

    /**
     * The DQL expression over the contact "p" that each sortable attribute sorts by, by its key
     * and by its aliases, so an older sort such as "registration" still sorts.
     *
     * @return array<string, string>
     */
    public function sorts(): array
    {
        $sorts = [];
        foreach ($this->attributes(self::LIST) as $attribute) {
            if (null !== $attribute->sort) {
                foreach ([$attribute->key, ...$attribute->aliases] as $key) {
                    $sorts[$key] = $attribute->sort;
                }
            }
        }

        return $sorts;
    }

    /**
     * The roles as one line of text, for the second line of a row on a phone.
     */
    public function roles(PartyRow $row): string
    {
        return implode(', ', array_map(fn (PartyRole $role): string => $this->translator->trans('party.role.'.$role->value), $row->roles));
    }

    private static function statusOf(PartyRow $row): string
    {
        return match (true) {
            $row->unreliablePayer => 'unreliable',
            $row->archived => 'archived',
            default => 'active',
        };
    }

    /**
     * The label of each value of an enum, by value.
     *
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, string>
     */
    private function options(array $cases, string $prefix): array
    {
        $options = [];
        foreach ($cases as $case) {
            $options[(string) $case->value] = $prefix.$case->value;
        }

        return $options;
    }
}
