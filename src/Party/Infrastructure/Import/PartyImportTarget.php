<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Import;

use App\Audit\Application\AuditCorrelation;
use App\Import\Application\Target\ImportTarget;
use App\Import\Application\Target\RecordMatch;
use App\Import\Application\Target\RecordSnapshot;
use App\Import\Application\Target\WriteResult;
use App\Import\Domain\BlankCells;
use App\Import\Domain\FieldDefinition;
use App\Import\Domain\FieldType;
use App\Import\Domain\HeaderName;
use App\Import\Domain\RowError;
use App\Party\Application\ArchiveParty;
use App\Party\Application\CreateParty;
use App\Party\Application\ImportedContact;
use App\Party\Application\ImportedParty;
use App\Party\Application\ImportPartyValues;
use App\Party\Application\LinkContactPerson;
use App\Party\Application\Parties;
use App\Party\Application\PartyAddresses;
use App\Party\Application\PartyBankAccounts;
use App\Party\Application\PartyDetails;
use App\Party\Application\PartyInput;
use App\Party\Application\PartyQuery;
use App\Party\Application\RecheckRegistry;
use App\Party\Application\UpdateParty;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\ContactLink;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\Iban;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyBankAccount;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PartyRole;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Infrastructure\Doctrine\DoctrinePartyChanges;
use App\Shared\Domain\Currency;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Contacts as an import target. A row creates a party with its billing
 * address, e-mails, delivery address, bank account and contact person, or
 * merges into a stored one: values overwrite, children that are missing are
 * added, nothing is removed. An undo puts the fields back and removes exactly
 * what the write added. The public registers are never asked during an
 * import; a party with a new VAT id is rechecked in the background.
 */
final readonly class PartyImportTarget implements ImportTarget
{
    public const string KEY = 'party';

    private const array MATCH_KEYS = ['id', 'registration_number', 'vat_id', 'email'];

    /** The languages whose role names a roles cell may use. */
    private const array ROLE_NAME_LOCALES = ['cs', 'en'];

    /** The children an import adds, as noted in the snapshot, by their entity. */
    private const array CHILDREN = [
        'emails' => PartyEmail::class,
        'addresses' => PartyAddress::class,
        'bankAccounts' => PartyBankAccount::class,
        'contactLinks' => ContactLink::class,
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ImportPartyValues $reader,
        private CreateParty $createParty,
        private UpdateParty $updateParty,
        private ArchiveParty $archiveParty,
        private LinkContactPerson $linkContactPerson,
        private Parties $store,
        private PartyAddresses $addresses,
        private PartyBankAccounts $bankAccounts,
        private PartyDetails $details,
        private PartyQuery $parties,
        private DoctrinePartyChanges $changes,
        private MessageBusInterface $bus,
        private AuditCorrelation $correlation,
        private TranslatorInterface $translator,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function labelKey(): string
    {
        return 'import.target.party';
    }

    public function fields(): array
    {
        $field = $this->field(...);

        return [
            $field('id', FieldType::Integer, ['id']),
            $field('display_name', FieldType::Text, ['nazev', 'nazev firmy', 'firma', 'obchodni jmeno', 'name', 'company', 'company name'], required: true),
            $field('kind', FieldType::Choice, ['druh', 'typ', 'kind', 'type'], choices: array_map(static fn (PartyKind $kind): string => $kind->value, PartyKind::cases()), choiceNames: $this->kindNames()),
            $field('registration_number', FieldType::Identifier, ['ic', 'ico', 'registration number', 'company id']),
            $field('registration_country', FieldType::Country, ['zeme registrace', 'registration country']),
            $field('vat_id', FieldType::Identifier, ['dic', 'vat', 'vat id', 'vat number']),
            $field('email', FieldType::EmailList, ['e mail', 'email', 'e mailova adresa']),
            $field('copy_email', FieldType::EmailList, ['dalsi prijemci', 'kopie', 'copy', 'cc']),
            $field('invoice_email', FieldType::Email, ['e mail pro faktury', 'fakturacni e mail', 'invoice email']),
            $field('phone', FieldType::Phone, ['telefon', 'tel', 'phone']),
            $field('mobile', FieldType::Phone, ['mobil', 'mobile']),
            $field('website', FieldType::Text, ['www', 'web', 'website']),
            $field('roles', FieldType::Text, ['role', 'roles'], choices: array_map(static fn (PartyRole $role): string => $role->value, PartyRole::cases())),
            ...$this->address('billing', ['ulice', 'street', 'adresa'], ['mesto', 'obec', 'city'], ['psc', 'postal code', 'zip'], ['stat', 'zeme', 'country']),
            ...$this->address('delivery', ['dodaci ulice', 'delivery street'], ['dodaci mesto', 'delivery city'], ['dodaci psc', 'delivery postal code'], ['dodaci stat', 'delivery country']),
            $field('bank_account_iban', FieldType::Identifier, ['iban'], 'bank'),
            $field('bank_account_bic', FieldType::Identifier, ['swift', 'bic'], 'bank'),
            $field('bank_account_number', FieldType::Text, ['cislo uctu', 'ucet', 'account number'], 'bank'),
            $field('bank_code', FieldType::Identifier, ['kod banky', 'bank code'], 'bank'),
            $field('contact_name', FieldType::Text, ['kontaktni osoba', 'contact person'], 'contact'),
            $field('contact_title', FieldType::Text, ['titul', 'title'], 'contact'),
            $field('contact_first_name', FieldType::Text, ['jmeno', 'first name'], 'contact'),
            $field('contact_last_name', FieldType::Text, ['prijmeni', 'last name', 'surname'], 'contact'),
            $field('contact_email', FieldType::Email, ['e mail kontaktni osoby', 'contact email'], 'contact'),
            $field('contact_phone', FieldType::Phone, ['telefon kontaktni osoby', 'contact phone'], 'contact'),
            $field('contact_position', FieldType::Text, ['pozice', 'funkce', 'position'], 'contact'),
            $field('due_days', FieldType::Integer, ['splatnost', 'due days']),
            $field('currency', FieldType::Choice, ['mena', 'currency'], choices: array_map(static fn (Currency $currency): string => $currency->value, Currency::cases())),
            $field('payment_method', FieldType::Choice, ['zpusob platby', 'forma uhrady', 'payment method'], choices: array_map(static fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases())),
            $field('private_note', FieldType::Text, ['poznamka', 'note']),
        ];
    }

    /**
     * @param list<string>          $aliases
     * @param list<string>          $choices
     * @param array<string, string> $choiceNames
     */
    private function field(string $key, FieldType $type, array $aliases = [], string $group = '', bool $required = false, array $choices = [], array $choiceNames = []): FieldDefinition
    {
        return new FieldDefinition($key, 'import.field.party.'.$key, $type, $required, $aliases, $choices, $group, $choiceNames);
    }

    /**
     * @param list<string> $street
     * @param list<string> $city
     * @param list<string> $postalCode
     * @param list<string> $country
     *
     * @return list<FieldDefinition>
     */
    private function address(string $group, array $street, array $city, array $postalCode, array $country): array
    {
        return [
            $this->field($group.'_street', FieldType::Text, $street, $group),
            $this->field($group.'_city', FieldType::Text, $city, $group),
            $this->field($group.'_postal_code', FieldType::Text, $postalCode, $group),
            $this->field($group.'_country', FieldType::Country, $country, $group),
        ];
    }

    public function matchKeys(): array
    {
        return self::MATCH_KEYS;
    }

    public function validate(array $values): array
    {
        $errors = [];
        foreach ($this->read($values)->errors as $field => [$messageKey, $parameters]) {
            $errors[] = new RowError($field, $messageKey, $parameters);
        }

        return $errors;
    }

    public function match(string $matchKey, array $values): array
    {
        $row = $this->read($values);
        [$where, $parameter] = match ($matchKey) {
            'id' => ['p.id = :value', \is_int($values['id'] ?? null) ? $values['id'] : null],
            'registration_number' => ['p.registrationCountry = :country AND p.registrationKey = :value', $row->registrationNumber?->normalised()],
            'vat_id' => ['p.vatId = :value', $row->vatId?->toString()],
            // A contact person often shares the company's address; like activeIds(), the match is about the company.
            'email' => ['p.id IN (SELECT IDENTITY(e.party) FROM '.PartyEmail::class.' e WHERE e.addressKey = :value)'
                .' AND NOT EXISTS (SELECT 1 FROM '.ContactLink::class.' l WHERE l.person = p)', null === $row->generalEmail ? null : PartyEmail::key($row->generalEmail)],
            default => throw new \InvalidArgumentException(\sprintf('"%s" is not a match key of contacts.', $matchKey)),
        };
        if (null === $parameter) {
            return [];
        }
        $query = $this->em->createQuery('SELECT p.id, p.archivedAt FROM '.Party::class.' p WHERE '.$where.' ORDER BY p.id')->setParameter('value', $parameter);
        if ('registration_number' === $matchKey) {
            $query->setParameter('country', $row->registrationNumber?->countryCode);
        }

        /** @var list<array{id: int, archivedAt: \DateTimeImmutable|null}> $found */
        $found = $query->getArrayResult();

        return array_map(static fn (array $party): RecordMatch => new RecordMatch($party['id'], null !== $party['archivedAt']), $found);
    }

    public function create(array $values): WriteResult
    {
        $row = $this->read($values);
        $party = $this->createParty->createFrom($this->reader->input($row));
        $added = $this->addChildren($party, $row);
        if ($party->vatId() instanceof VatId) {
            $this->recheck($party);
        }
        $persons = $added['persons'] ?? [];

        // A created party is archived by an undo, and so are the contact persons it brought.
        $snapshot = ['created' => true, 'children' => $this->children([$this->id($party), ...$persons])];
        if ([] !== $persons) {
            $snapshot['added'] = ['persons' => $persons];
        }

        return new WriteResult($this->id($party), new RecordSnapshot($snapshot));
    }

    public function update(int $id, array $values, BlankCells $blankCells): WriteResult
    {
        $party = $this->party($id);
        $billing = $this->details->billingAddress($party);
        $general = $this->details->generalEmail($party);
        $before = $this->snapshot($party, $billing, $general);
        $identifiers = $this->identifiers($party);

        $row = $this->read($values);
        $this->updateParty->update($party, $this->reader->merge($party, $billing?->address(), $general?->address() ?? '', $row, BlankCells::Clear === $blankCells));
        $added = $this->addChildren($party, $row);
        if (!$general instanceof PartyEmail && ($new = $this->details->generalEmail($party)) instanceof PartyEmail) {
            $added['emails'][] = $this->childId($new);
        }
        if ($party->vatId() instanceof VatId && $this->identifiers($party) !== $identifiers) {
            $this->recheck($party);
        }

        return new WriteResult($id, new RecordSnapshot($before + ['added' => $added, 'children' => $this->children([$id, ...$added['persons'] ?? []])]));
    }

    public function restoreArchived(int $id): void
    {
        $this->archiveParty->restore([$this->party($id)]);
    }

    public function archive(int $id): RecordSnapshot
    {
        $this->archiveParty->archive([$this->party($id)]);

        return new RecordSnapshot(['archived' => false]);
    }

    public function revert(int $id, RecordSnapshot $before): void
    {
        $party = $this->party($id);
        $data = $before->data;
        $added = $this->added($before);
        $persons = $this->ids($added['persons'] ?? []);
        if ($before->isNone() || true === ($data['created'] ?? false)) {
            $this->archiveParty->archive([$party, ...$this->parties->findMany($persons)]);

            return;
        }

        $this->restoreFields($party, $data);
        foreach (self::CHILDREN as $key => $class) {
            foreach ($this->ids($added[$key] ?? []) as $childId) {
                $child = $this->em->find($class, $childId);
                if (null !== $child) {
                    $this->em->remove($child);
                }
            }
        }
        $this->em->flush();
        if ([] !== $persons) {
            $this->archiveParty->archive($this->parties->findMany($persons));
        }
    }

    /**
     * The party, the persons the write created, the children they have now and
     * the children they had right after the write: a child removed since then
     * is found by its id.
     */
    public function changedSince(int $id, RecordSnapshot $before, \DateTimeImmutable $since, array $exceptCorrelationIds): bool
    {
        $added = $this->added($before);
        $noted = \is_array($before->data['children'] ?? null) ? $before->data['children'] : [];
        $known = [];
        foreach (self::CHILDREN as $key => $class) {
            $known[$class] = [...$this->ids($added[$key] ?? []), ...$this->ids($noted[$key] ?? [])];
        }

        return $this->changes->changedSince([$id, ...$this->ids($added['persons'] ?? [])], $known, $since, $exceptCorrelationIds);
    }

    /**
     * Reads a row, with the roles named in Czech or English turned into their keys first.
     *
     * @param array<string, mixed> $values
     */
    private function read(array $values): ImportedParty
    {
        $roles = $values['roles'] ?? null;
        if (\is_string($roles)) {
            $names = $this->roleNames();
            $words = preg_split('/[,;]/', $roles);
            $values['roles'] = implode(',', array_map(static fn (string $word): string => $names[HeaderName::normalise($word)] ?? $word, false === $words ? [] : $words));
        }

        return $this->reader->read($values);
    }

    /**
     * The kinds by their Czech and English names, compared as headers are.
     *
     * @return array<string, string> folded name => kind key
     */
    private function kindNames(): array
    {
        $names = [];
        foreach (PartyKind::cases() as $kind) {
            foreach (self::ROLE_NAME_LOCALES as $locale) {
                $names[HeaderName::normalise($this->translator->trans('party.kind.'.$kind->value, [], 'messages', $locale))] = $kind->value;
            }
        }

        return $names;
    }

    /**
     * The roles by their key and their Czech and English names, compared as
     * headers are: without case and diacritics.
     *
     * @return array<string, string> folded name => role key
     */
    private function roleNames(): array
    {
        $names = [];
        foreach (PartyRole::cases() as $role) {
            $names[HeaderName::normalise($role->value)] = $role->value;
            foreach (self::ROLE_NAME_LOCALES as $locale) {
                $names[HeaderName::normalise($this->translator->trans('party.role.'.$role->value, [], 'messages', $locale))] = $role->value;
            }
        }

        return $names;
    }

    /**
     * The ids of the children of the parties, by the keys of CHILDREN.
     *
     * @param list<int> $partyIds
     *
     * @return array<string, list<int>>
     */
    private function children(array $partyIds): array
    {
        $byClass = $this->changes->childrenOf($partyIds);
        $children = [];
        foreach (self::CHILDREN as $key => $class) {
            if (isset($byClass[$class])) {
                $children[$key] = $byClass[$class];
            }
        }

        return $children;
    }

    /**
     * Contact persons of a company stand with their company: a full replace never archives them on its own.
     */
    public function activeIds(): iterable
    {
        /** @var list<int|string> $ids */
        $ids = $this->em->createQuery(
            'SELECT p.id FROM '.Party::class.' p WHERE p.archivedAt IS NULL'
            .' AND NOT EXISTS (SELECT 1 FROM '.ContactLink::class.' l WHERE l.person = p) ORDER BY p.id',
        )->getSingleColumnResult();

        foreach ($ids as $id) {
            yield (int) $id;
        }
    }

    public function export(array $ids): iterable
    {
        foreach ($this->parties->findMany($ids) as $party) {
            yield $this->exportRow($party);
        }
        // An export reads whole address books in batches; the batch is not needed any more.
        $this->em->clear();
    }

    /**
     * Adds what the row brings and the party lacks: e-mails by address, the
     * delivery address when it has none, the bank account by IBAN (primary when
     * there is none) and the contact person, found among the company's persons
     * by e-mail or name, or created.
     *
     * @return array<string, list<int>> the ids of what was added, by kind
     */
    private function addChildren(Party $party, ImportedParty $row): array
    {
        $emails = $this->emails($party, $row->copyEmails, $row->invoiceEmail);
        $addresses = $this->deliveryAddress($party, $row->deliveryAddress);
        $accounts = $this->bankAccount($party, $row->iban, $row->bic);
        [$links, $persons] = $this->contactPerson($party, $row);
        // Bank accounts and contact persons are stored by their services; e-mails and addresses need their ids.
        if ([] !== $emails || [] !== $addresses) {
            $this->em->flush();
        }

        return array_filter([
            'emails' => array_map($this->childId(...), $emails),
            'addresses' => array_map($this->childId(...), $addresses),
            'bankAccounts' => $accounts,
            'contactLinks' => $links,
            'persons' => $persons,
        ]);
    }

    /**
     * @param list<string> $copies
     *
     * @return list<PartyEmail>
     */
    private function emails(Party $party, array $copies, ?string $invoices): array
    {
        $wanted = array_map(static fn (string $address): array => [$address, EmailPurpose::Copy], $copies);
        if (null !== $invoices) {
            $wanted[] = [$invoices, EmailPurpose::Invoices];
        }
        if ([] === $wanted) {
            return [];
        }
        $known = array_map(static fn (PartyEmail $email): string => mb_strtolower($email->address()), $this->details->emails($party));
        $added = [];
        foreach ($wanted as [$address, $purpose]) {
            if (!\in_array(mb_strtolower($address), $known, true)) {
                $email = new PartyEmail($party, $address, $purpose);
                $this->store->addEmail($email);
                $known[] = mb_strtolower($address);
                $added[] = $email;
            }
        }

        return $added;
    }

    /**
     * @return list<PartyAddress>
     */
    private function deliveryAddress(Party $party, ?Address $address): array
    {
        if (!$address instanceof Address) {
            return [];
        }
        foreach ($this->details->addresses($party) as $existing) {
            if (AddressType::Delivery === $existing->type()) {
                return [];
            }
        }
        $delivery = new PartyAddress($party, AddressType::Delivery, $address);
        $this->addresses->add($delivery);

        return [$delivery];
    }

    /**
     * @return list<int>
     */
    private function bankAccount(Party $party, ?Iban $iban, ?string $bic): array
    {
        if (!$iban instanceof Iban) {
            return [];
        }
        $primary = false;
        foreach ($this->details->bankAccounts($party) as $existing) {
            if ($existing->iban()->toString() === $iban->toString()) {
                return [];
            }
            $primary = $primary || $existing->isPrimary();
        }
        $account = new PartyBankAccount($party, $iban, $bic, '', !$primary);
        $this->bankAccounts->add($account);

        return [$this->childId($account)];
    }

    /**
     * @return array{list<int>, list<int>} the ids of the new link and of the new person
     */
    private function contactPerson(Party $company, ImportedParty $row): array
    {
        $contact = $row->contact;
        if (!$contact instanceof ImportedContact || PartyKind::Person === $company->kind()) {
            return [[], []];
        }
        $links = array_values(array_filter($this->details->contactLinks($company), static fn (ContactLink $link): bool => $link->company() === $company));
        foreach ($links as $link) {
            $person = $link->person();
            if ($person->displayName() === $contact->name) {
                return [[], []];
            }
            if ('' !== $contact->email && \in_array(mb_strtolower($contact->email), array_map(static fn (PartyEmail $email): string => mb_strtolower($email->address()), $this->details->emails($person)), true)) {
                return [[], []];
            }
        }

        $person = $this->createParty->createFrom(new PartyInput(
            PartyKind::Person,
            $contact->name,
            null,
            null,
            $contact->email,
            $contact->phone,
            [],
            new Address('', '', '', $row->billingAddress->countryCode),
        ));
        $link = $this->linkContactPerson->link($company, $person, $contact->position, !array_any($links, static fn (ContactLink $link): bool => $link->isPrimary()));

        return [[$this->childId($link)], [$this->id($person)]];
    }

    /**
     * Everything an undo of an update puts back: the fields, the billing address and the general e-mail.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Party $party, ?PartyAddress $billing, ?PartyEmail $general): array
    {
        $registration = $party->registrationNumber();
        $defaults = $party->invoicingDefaults();
        $address = $billing?->address();

        return [
            'party' => [
                'displayName' => $party->displayName(),
                'registrationCountry' => $registration?->countryCode,
                'registrationNumber' => $registration?->value,
                'vatId' => $party->vatId()?->toString(),
                'phone' => $party->phone(),
                'mobile' => $party->mobile(),
                'website' => $party->website(),
                'roles' => array_map(static fn (PartyRole $role): string => $role->value, $party->roles()),
                'privateNote' => $party->privateNote(),
                'dueDays' => $defaults->dueDays,
                'currency' => $defaults->currency->value,
                'language' => $defaults->language->value,
                'paymentMethod' => $defaults->paymentMethod->value,
                'reverseCharge' => $defaults->reverseCharge,
            ],
            'billing' => $address instanceof Address ? ['street' => $address->street, 'city' => $address->city, 'postalCode' => $address->postalCode, 'countryCode' => $address->countryCode] : null,
            'generalEmail' => $general instanceof PartyEmail ? ['id' => $general->id(), 'address' => $general->address()] : null,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function restoreFields(Party $party, array $data): void
    {
        $fields = \is_array($data['party'] ?? null) ? $data['party'] : [];
        $text = static fn (string $key): string => \is_string($fields[$key] ?? null) ? $fields[$key] : '';
        $party->rename($text('displayName'));
        $country = $fields['registrationCountry'] ?? null;
        $number = $fields['registrationNumber'] ?? null;
        $vatId = $fields['vatId'] ?? null;
        $party->changeIdentifiers(
            \is_string($country) && \is_string($number) ? new RegistrationNumber($country, $number) : null,
            \is_string($vatId) ? ($vatId === $party->vatId()?->toString() ? $party->vatId() : VatId::fromString($vatId)) : null,
        );
        $party->changePhone($text('phone'));
        $party->changeMobile($text('mobile'));
        $party->changeWebsite($text('website'));
        $party->assignRoles(array_values(array_filter(array_map(static fn (mixed $role): ?PartyRole => \is_string($role) ? PartyRole::tryFrom($role) : null, \is_array($fields['roles'] ?? null) ? $fields['roles'] : []))));
        $party->changePrivateNote($text('privateNote'));
        $standard = InvoicingDefaults::standard();
        $party->changeInvoicingDefaults(new InvoicingDefaults(
            \is_int($fields['dueDays'] ?? null) ? $fields['dueDays'] : $standard->dueDays,
            Currency::tryFrom($text('currency')) ?? $standard->currency,
            DocumentLanguage::tryFrom($text('language')) ?? $standard->language,
            PaymentMethod::tryFrom($text('paymentMethod')) ?? $standard->paymentMethod,
            true === ($fields['reverseCharge'] ?? null),
        ));

        $billing = $data['billing'] ?? null;
        $current = $this->details->billingAddress($party);
        if (\is_array($billing) && $current instanceof PartyAddress) {
            $part = static fn (string $key): string => \is_string($billing[$key] ?? null) ? $billing[$key] : '';
            $current->relocate(new Address($part('street'), $part('city'), $part('postalCode'), $part('countryCode')));
        }

        $general = $data['generalEmail'] ?? null;
        if (\is_array($general) && \is_int($general['id'] ?? null) && \is_string($general['address'] ?? null)) {
            $email = $this->em->find(PartyEmail::class, $general['id']);
            $email?->change($general['address'], EmailPurpose::General);
        }
    }

    /**
     * @return array<string, string>
     */
    private function exportRow(Party $party): array
    {
        $emails = $this->details->emails($party);
        $purpose = static fn (EmailPurpose $purpose): array => array_values(array_map(static fn (PartyEmail $email): string => $email->address(), array_filter($emails, static fn (PartyEmail $email): bool => $purpose === $email->purpose())));
        $addresses = $this->details->addresses($party);
        $address = static function (AddressType $type) use ($addresses): ?Address {
            foreach ($addresses as $address) {
                if ($type === $address->type()) {
                    return $address->address();
                }
            }

            return null;
        };
        $billing = $address(AddressType::Billing);
        $delivery = $address(AddressType::Delivery);
        $account = $this->details->bankAccounts($party)[0] ?? null;
        $links = array_values(array_filter($this->details->contactLinks($party), static fn (ContactLink $link): bool => $link->company() === $party));
        usort($links, static fn (ContactLink $a, ContactLink $b): int => (int) $b->isPrimary() <=> (int) $a->isPrimary());
        $link = $links[0] ?? null;
        $person = $link?->person();
        $registration = $party->registrationNumber();
        $defaults = $party->invoicingDefaults();

        return [
            'id' => (string) $this->id($party),
            'display_name' => $party->displayName(),
            'kind' => $this->translator->trans('party.kind.'.$party->kind()->value),
            'registration_number' => $registration->value ?? '',
            'registration_country' => $registration->countryCode ?? '',
            'vat_id' => $party->vatId()?->toString() ?? '',
            // Without a general e-mail the copies stay copies: the first address of "email" becomes the general one.
            'email' => [] === $purpose(EmailPurpose::General) ? '' : implode(', ', [...$purpose(EmailPurpose::General), ...$purpose(EmailPurpose::Copy)]),
            'copy_email' => [] === $purpose(EmailPurpose::General) ? implode(', ', $purpose(EmailPurpose::Copy)) : '',
            'invoice_email' => $purpose(EmailPurpose::Invoices)[0] ?? '',
            'phone' => $party->phone(),
            'mobile' => $party->mobile(),
            'website' => $party->website(),
            'roles' => implode(', ', array_map(fn (PartyRole $role): string => $this->translator->trans('party.role.'.$role->value), $party->roles())),
            'billing_street' => $billing->street ?? '',
            'billing_city' => $billing->city ?? '',
            'billing_postal_code' => $billing->postalCode ?? '',
            'billing_country' => $billing->countryCode ?? '',
            'delivery_street' => $delivery->street ?? '',
            'delivery_city' => $delivery->city ?? '',
            'delivery_postal_code' => $delivery->postalCode ?? '',
            'delivery_country' => $delivery->countryCode ?? '',
            'bank_account_iban' => $account?->iban()->toString() ?? '',
            'bank_account_bic' => $account?->bic() ?? '',
            'bank_account_number' => '',
            'bank_code' => '',
            'contact_name' => $person?->displayName() ?? '',
            'contact_title' => '',
            'contact_first_name' => '',
            'contact_last_name' => '',
            'contact_email' => null === $person ? '' : ($this->details->generalEmail($person)?->address() ?? ''),
            'contact_phone' => $person?->phone() ?? '',
            'contact_position' => $link?->position() ?? '',
            'due_days' => (string) $defaults->dueDays,
            'currency' => $defaults->currency->value,
            'payment_method' => $defaults->paymentMethod->value,
            'private_note' => $party->privateNote(),
        ];
    }

    /**
     * @return array{string|null, string|null}
     */
    private function identifiers(Party $party): array
    {
        return [$party->registrationNumber()?->normalised(), $party->vatId()?->toString()];
    }

    private function recheck(Party $party): void
    {
        $this->bus->dispatch(new RecheckRegistry($this->id($party), $this->correlation->current()));
    }

    private function party(int $id): Party
    {
        return $this->parties->find($id) ?? throw new \DomainException(\sprintf('There is no contact %d.', $id));
    }

    private function id(Party $party): int
    {
        return $party->id() ?? throw new \LogicException('The party is not stored.');
    }

    private function childId(PartyEmail|PartyAddress|PartyBankAccount|ContactLink $child): int
    {
        return $child->id() ?? throw new \LogicException('The child is not stored.');
    }

    /**
     * What the write that left the snapshot added, by kind.
     *
     * @return array<array-key, mixed>
     */
    private function added(RecordSnapshot $before): array
    {
        $added = $before->data['added'] ?? null;

        return \is_array($added) ? $added : [];
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $ids): array
    {
        return \is_array($ids) ? array_values(array_filter($ids, \is_int(...))) : [];
    }
}
