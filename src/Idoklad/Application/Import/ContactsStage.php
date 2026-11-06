<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportOutcome;
use App\Import\Application\Target\ImportTarget;
use App\Import\Application\Target\RecordMatch;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Domain\BlankCells;
use App\Import\Domain\FieldDefinition;
use App\Import\Domain\NormalisationOptions;
use App\Import\Domain\RowError;
use App\Import\Domain\ValueNormaliser;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Application\ImportPartyValues;
use App\Party\Application\PartyDetails;
use App\Party\Application\PartyQuery;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\Party;
use App\Shared\Domain\Countries;

/**
 * iDoklad contacts as Foresi contacts, written by the contact import target as
 * a file import would write them, so the rules of contacts hold. A contact is
 * found by its registration number, else by its exact name (case and spaces
 * aside) when that leaves no two companies with different numbers matched; a
 * contact found gets only what it lacks, never a value the user has. Neither
 * found nor creatable (a value Foresi cannot read, a country iDoklad does not
 * name) is a refusal with the reasons, never a guess. The reminder setting is
 * the firm's own setting of a created contact.
 */
final readonly class ContactsStage implements ImportStage
{
    public const string KEY = 'contacts';
    public const string UNKNOWN_COUNTRY = 'idoklad.import.contact.unknown_country';
    public const string AMBIGUOUS = 'idoklad.import.contact.ambiguous';

    private const string TARGET = 'party';

    /** The fields of an iDoklad contact by the field of the contact import they fill. */
    private const array FIELDS = [
        'display_name' => 'CompanyName',
        'registration_number' => 'IdentificationNumber',
        'vat_id' => 'VatIdentificationNumber',
        'email' => 'Email',
        'phone' => 'Phone',
        'mobile' => 'Mobile',
        'website' => 'Www',
        'billing_street' => 'Street',
        'billing_city' => 'City',
        'billing_postal_code' => 'PostalCode',
        'contact_title' => 'Title',
        'contact_first_name' => 'Firstname',
        'contact_last_name' => 'Surname',
        'bank_account_iban' => 'Iban',
        'bank_account_bic' => 'Swift',
        'due_days' => 'DefaultInvoiceMaturity',
        'private_note' => 'Note',
    ];

    private const array DELIVERY = ['delivery_street' => 'Street', 'delivery_city' => 'City', 'delivery_postal_code' => 'PostalCode'];

    /** Fields a found contact may lack as a group: an address is filled whole or not at all. */
    private const array BILLING = ['billing_street', 'billing_city', 'billing_postal_code', 'billing_country'];
    private const array BANK = ['bank_account_iban', 'bank_account_bic'];
    private const array CONTACT_PERSON = ['contact_title', 'contact_first_name', 'contact_last_name'];

    public function __construct(
        private TargetRegistry $targets,
        private ValueNormaliser $normaliser,
        private ImportPartyValues $reader,
        private PartyQuery $parties,
        private PartyDetails $details,
        private ContactSettingsForFirm $settings,
        private ContactNames $names,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function order(): int
    {
        return 100;
    }

    public function run(ImportContext $context): void
    {
        $target = $this->targets->get(self::TARGET);
        $fields = [];
        foreach ($target->fields() as $field) {
            $fields[$field->key] = $field;
        }
        $byName = [];
        foreach ($this->names->all() as $id => $name) {
            $byName[$this->fold($name)][] = $id;
        }

        foreach ($context->records(IdokladResource::Contacts) as $record) {
            $id = $record->externalId();
            $linked = $context->linkedTo(IdokladResource::Contacts, $id);
            if (null !== $linked) {
                $context->report(self::KEY, $id, ImportOutcome::AlreadyLinked, $linked);

                continue;
            }

            [$values, $refusals] = $this->values($context, $record->payload(), $fields);
            if ([] === $refusals) {
                foreach ($this->reader->read($values)->errors as $field => [$key, $parameters]) {
                    $refusals[] = new RowError($field, $key, $parameters);
                }
            }
            if ([] !== $refusals) {
                $this->refuse($context, $id, $refusals);

                continue;
            }

            $row = $this->reader->read($values);
            $found = $this->find($target, $values, $byName[$this->fold($row->displayName)] ?? [], $row->registrationNumber instanceof \App\Party\Domain\RegistrationNumber);
            if (\count($found) > 1) {
                $this->refuse($context, $id, [new RowError('display_name', self::AMBIGUOUS, ['value' => $row->displayName])]);

                continue;
            }

            if ([] === $found) {
                $partyId = $target->create($values)->id;
                $byName[$this->fold($row->displayName)][] = $partyId;
                $this->remind($context, $partyId, $record->payload()['SendReminders'] ?? null);
                $context->link(IdokladResource::Contacts, $id, $partyId);
                $context->report(self::KEY, $id, ImportOutcome::Created, $partyId);

                continue;
            }

            $party = $found[0];
            $missing = $this->missing($party, $values);
            if ([] !== $missing) {
                $target->update($party->id(), $missing, BlankCells::Keep);
            }
            $context->link(IdokladResource::Contacts, $id, $party->id());
            $context->report(self::KEY, $id, ImportOutcome::Matched, $party->id(), values: ['filled' => implode(',', array_keys($missing))]);
        }
    }

    /**
     * The values of the contact by import field, read as the file import reads
     * a cell, and what could not be read.
     *
     * @param array<mixed>                   $payload
     * @param array<string, FieldDefinition> $fields
     *
     * @return array{array<string, mixed>, list<RowError>}
     */
    private function values(ImportContext $context, array $payload, array $fields): array
    {
        $raw = [];
        foreach (self::FIELDS as $field => $source) {
            $raw[$field] = $payload[$source] ?? null;
        }
        $refusals = [];
        $country = $this->country($context, $payload['CountryId'] ?? null);
        if (null === $country) {
            $refusals[] = new RowError('billing_country', self::UNKNOWN_COUNTRY, ['value' => $this->text($payload['CountryId'] ?? null)]);
        }
        $raw['billing_country'] = $raw['registration_country'] = $country;

        $delivery = $this->delivery($payload['DeliveryAddresses'] ?? null);
        if (null !== $delivery) {
            foreach (self::DELIVERY as $field => $source) {
                $raw[$field] = $delivery[$source] ?? null;
            }
            $raw['delivery_country'] = $this->country($context, $delivery['CountryId'] ?? null);
            if (null === $raw['delivery_country']) {
                $refusals[] = new RowError('delivery_country', self::UNKNOWN_COUNTRY, ['value' => $this->text($delivery['CountryId'] ?? null)]);
            }
        }

        $options = new NormalisationOptions();
        $values = [];
        foreach ($raw as $field => $value) {
            $value = $this->normaliser->normalise($fields[$field], $this->text($value), $options);
            if ($value instanceof RowError) {
                $refusals[] = $value;
            } else {
                $values[$field] = $value;
            }
        }

        return [$values, $refusals];
    }

    /**
     * The code of a country of the account's code list, by its Czech or English name.
     */
    private function country(ImportContext $context, mixed $countryId): ?string
    {
        $country = \is_int($countryId) ? $context->record(IdokladResource::Countries, $countryId)?->payload() : null;

        return Countries::find($this->text($country['Name'] ?? null)) ?? Countries::find($this->text($country['NameEnglish'] ?? null));
    }

    /**
     * The default delivery address, or the first one.
     *
     * @return array<mixed>|null
     */
    private function delivery(mixed $addresses): ?array
    {
        $addresses = \is_array($addresses) ? array_values(array_filter($addresses, \is_array(...))) : [];
        foreach ($addresses as $address) {
            if (true === ($address['IsDefault'] ?? null)) {
                return $address;
            }
        }

        return $addresses[0] ?? null;
    }

    /**
     * The contacts the iDoklad contact is: those with its registration number,
     * else those with its name and no other registration number.
     *
     * @param array<string, mixed> $values
     * @param list<string>         $named
     *
     * @return list<Party>
     */
    private function find(ImportTarget $target, array $values, array $named, bool $registered): array
    {
        $ids = array_map(static fn (RecordMatch $match): string => $match->id, $target->match('registration_number', $values));
        $parties = $this->parties->findMany([] !== $ids ? $ids : $named);
        if ([] !== $ids) {
            return $parties;
        }

        return array_values(array_filter($parties, static fn (Party $party): bool => !$registered || !$party->registrationNumber() instanceof \App\Party\Domain\RegistrationNumber));
    }

    /**
     * The values a found contact lacks: a field it has stays out, so the
     * update leaves it alone. The name and the due period always have a value.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function missing(Party $party, array $values): array
    {
        $has = [
            'display_name' => true,
            'due_days' => true,
            'registration_number' => $party->registrationNumber() instanceof \App\Party\Domain\RegistrationNumber,
            'vat_id' => $party->vatId() instanceof \App\Party\Domain\VatId,
            'email' => $this->details->generalEmail($party) instanceof \App\Party\Domain\PartyEmail,
            'phone' => '' !== $party->phone(),
            'mobile' => '' !== $party->mobile(),
            'website' => '' !== $party->website(),
            'private_note' => '' !== $party->privateNote(),
        ];
        $billing = $this->details->billingAddress($party)?->address();
        $has += array_fill_keys(self::BILLING, $billing instanceof \App\Party\Domain\Address && '' !== $billing->street.$billing->city.$billing->postalCode);
        $has += array_fill_keys(self::BANK, [] !== $this->details->bankAccounts($party));
        $has += array_fill_keys(self::CONTACT_PERSON, [] !== $this->details->contactLinks($party));

        $missing = array_filter($values, static fn (mixed $value, string $field): bool => null !== $value && !($has[$field] ?? false), \ARRAY_FILTER_USE_BOTH);
        // The country only says where a registration number is read.
        unset($missing['registration_country']);
        if (isset($missing['registration_number'])) {
            $missing['registration_country'] = $values['registration_country'];
        }

        return $missing;
    }

    /**
     * A created contact sends reminders for this firm as iDoklad did.
     */
    private function remind(ImportContext $context, string $partyId, mixed $sendReminders): void
    {
        if (!\is_bool($sendReminders)) {
            return;
        }
        $party = $this->parties->find($partyId) ?? throw new \LogicException('The created contact is stored.');
        $defaults = $this->settings->defaultsFor($party, $context->firm);
        if ($defaults->sendReminders !== $sendReminders) {
            $this->settings->changeDefaults($party, $context->firm, new InvoicingDefaults($defaults->dueDays, $defaults->currency, $defaults->language, $defaults->paymentMethod, $defaults->reverseCharge, $sendReminders));
        }
    }

    /**
     * @param list<RowError> $refusals
     */
    private function refuse(ImportContext $context, int $id, array $refusals): void
    {
        $values = [];
        foreach ($refusals as $refusal) {
            $values[$refusal->field] = (string) ($refusal->parameters['value'] ?? '');
        }
        $context->report(self::KEY, $id, ImportOutcome::Refused, reasons: array_values(array_unique(array_map(static fn (RowError $refusal): string => $refusal->messageKey, $refusals))), values: $values);
    }

    private function fold(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', $name));
    }

    private function text(mixed $value): string
    {
        return \is_string($value) || \is_int($value) ? (string) $value : '';
    }
}
