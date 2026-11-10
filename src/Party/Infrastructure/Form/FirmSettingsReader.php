<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Form;

use App\Identity\Application\OwnerCheck;
use App\Party\Application\ContactSettingsForFirm;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\InvoicingEmail;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyRole;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Shared\Infrastructure\Http\TypedAmount;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the scoped panels of the contact form. Only the firms it is given are
 * read; anything posted for another firm is ignored. firms[id][own][panel] says
 * what to do with a firm's panel: "1" stores the firm's own values, "0" is a
 * confirmed back to shared. Anything else leaves the panel alone, so a form
 * that showed shared values cannot delete an override set after it was rendered.
 * A panel posted without the reminder flag, such as from a form older than the
 * flag, keeps the flag stored for its scope. The hourly rate is the owner's: a
 * panel posted without it, or by anyone else, keeps the rate stored for its scope.
 *
 * @phpstan-import-type Values from FirmSettingsSubmission
 */
final readonly class FirmSettingsReader
{
    private const int MAX_DUE_DAYS = 365;

    public function __construct(private ContactSettingsForFirm $settings, private OwnerCheck $owner)
    {
    }

    /**
     * @param list<LegalEntity> $firms the active firms the form offered
     * @param Party|null        $party the contact as stored, null for a new one
     */
    public function read(Request $request, array $firms, ?Party $party = null): FirmSettingsSubmission
    {
        $posted = $request->request->all('firms');
        $parsed = new FirmSettingsParsed();
        $rates = $this->owner->isOwner();
        $locale = $request->getLocale();

        $shared = $posted[FirmSettingsSubmission::SHARED] ?? null;
        if (\is_array($shared)) {
            $this->shared($shared, $parsed, static fn (): InvoicingDefaults => $party?->invoicingDefaults() ?? InvoicingDefaults::standard(), $rates, $locale);
        }
        foreach ($firms as $firm) {
            $row = $posted[$firm->id()] ?? null;
            if (\is_array($row) && \is_array($row['own'] ?? null)) {
                $this->firm($firm->id(), $row, $parsed, fn (): InvoicingDefaults => $party instanceof Party ? $this->settings->defaultsFor($party, $firm) : InvoicingDefaults::standard(), $rates, $locale);
            }
        }

        $active = [];
        foreach ($request->request->all('panelScope') as $panel => $scope) {
            if (\is_string($panel) && \is_string($scope)) {
                $active[$panel] = $scope;
            }
        }

        return new FirmSettingsSubmission($parsed->values, $parsed->own, $parsed->errors, $active, $parsed->roles, $parsed->defaults, $parsed->emails, $parsed->archived);
    }

    /**
     * @param array<mixed>                  $row
     * @param \Closure(): InvoicingDefaults $stored the defaults stored for the scope
     */
    private function shared(array $row, FirmSettingsParsed $parsed, \Closure $stored, bool $rates, string $locale): void
    {
        $scope = FirmSettingsSubmission::SHARED;
        if (\array_key_exists('dueDays', $row)) {
            $defaults = $this->defaults($scope, $row, $parsed, $stored, $rates, $locale);
            if ($defaults instanceof InvoicingDefaults) {
                $parsed->defaults[$scope] = $defaults;
            }
        }
        if (\array_key_exists('invoiceEmails', $row)) {
            $emails = $this->emails($scope, $row, $parsed);
            if (null !== $emails) {
                $parsed->emails[$scope] = $emails;
            }
        }
        if (\array_key_exists('archived', $row) && \is_string($row['archivedBefore'] ?? null)) {
            // Only a change the owner made: a form as rendered must not undo what happened since.
            $before = $row['archivedBefore'];
            $parsed->values[$scope]['archivedBefore'] = $before;
            $archived = $this->archived($scope, $row, $parsed);
            if ($archived !== ('1' === $before)) {
                $parsed->archived[$scope] = $archived;
            }
        }
    }

    /**
     * @param array<mixed>                  $row
     * @param \Closure(): InvoicingDefaults $stored the defaults stored for the scope
     */
    private function firm(string $id, array $row, FirmSettingsParsed $parsed, \Closure $stored, bool $rates, string $locale): void
    {
        /** @var array<mixed> $own */
        $own = $row['own'];
        $sent = array_intersect_key($own, array_flip(['roles', 'invoicing', 'emails', 'archive']));
        foreach ($sent as $panel => $state) {
            if (!\in_array($state, ['0', '1'], true)) {
                continue;
            }
            $setting = '1' === $state;
            $parsed->own[$id][$panel] = $setting;
            match ($panel) {
                'roles' => $parsed->roles[$id] = $setting ? $this->roles($id, $row, $parsed) : null,
                'invoicing' => $this->firmDefaults($id, $row, $setting, $parsed, $stored, $rates, $locale),
                'emails' => $this->firmEmails($id, $row, $setting, $parsed),
                default => $parsed->archived[$id] = $setting && $this->archived($id, $row, $parsed),
            };
        }
    }

    /**
     * @param array<mixed>                  $row
     * @param \Closure(): InvoicingDefaults $stored
     */
    private function firmDefaults(string $id, array $row, bool $setting, FirmSettingsParsed $parsed, \Closure $stored, bool $rates, string $locale): void
    {
        $defaults = $setting ? $this->defaults($id, $row, $parsed, $stored, $rates, $locale) : null;
        if ($defaults instanceof InvoicingDefaults || !$setting) {
            $parsed->defaults[$id] = $defaults;
        }
    }

    /**
     * @param array<mixed> $row
     */
    private function firmEmails(string $id, array $row, bool $setting, FirmSettingsParsed $parsed): void
    {
        $emails = $setting ? $this->emails($id, $row, $parsed) : null;
        if (null !== $emails || !$setting) {
            $parsed->emails[$id] = $emails;
        }
    }

    /**
     * @param array<mixed> $row
     *
     * @return list<PartyRole>
     */
    private function roles(string $scope, array $row, FirmSettingsParsed $parsed): array
    {
        $posted = \is_array($row['roles'] ?? null) ? $row['roles'] : [];
        $roles = [];
        foreach ($posted as $role) {
            $role = \is_string($role) ? PartyRole::tryFrom($role) : null;
            if ($role instanceof PartyRole) {
                $roles[] = $role;
            }
        }
        $parsed->values[$scope]['roles'] = array_map(static fn (PartyRole $role): string => $role->value, $roles);

        return $roles;
    }

    /**
     * @param array<mixed>                  $row
     * @param \Closure(): InvoicingDefaults $stored the defaults stored for the scope, read only when a field is missing
     * @param bool                          $rates  whether the hourly rate is read; otherwise the stored one stays
     */
    private function defaults(string $scope, array $row, FirmSettingsParsed $parsed, \Closure $stored, bool $rates, string $locale): ?InvoicingDefaults
    {
        $value = [
            'dueDays' => $this->text($row['dueDays'] ?? ''),
            'currency' => $this->text($row['currency'] ?? ''),
            'language' => $this->text($row['language'] ?? ''),
            'paymentMethod' => $this->text($row['paymentMethod'] ?? ''),
            'reverseCharge' => isset($row['reverseCharge']) ? '1' : '',
            // A hidden "0" goes before the checkbox; a form without the field keeps what is stored.
            'sendReminders' => match ($row['sendReminders'] ?? null) {
                '0' => '',
                null => $stored()->sendReminders ? '1' : '',
                default => '1',
            },
        ];
        if ($rates && \array_key_exists('hourlyRate', $row)) {
            $value['hourlyRate'] = $this->text($row['hourlyRate']);
            $value['hourlyRateCurrency'] = $this->text($row['hourlyRateCurrency'] ?? '');
            $rateCurrency = Currency::tryFrom($value['hourlyRateCurrency']);
            $rate = $rateCurrency instanceof Currency ? TypedAmount::read($value['hourlyRate'], $rateCurrency, $locale) : false;
        } else {
            $rate = $stored()->hourlyRate();
        }
        $parsed->values[$scope] = $value + ($parsed->values[$scope] ?? []);

        $currency = Currency::tryFrom($value['currency']);
        $language = DocumentLanguage::tryFrom($value['language']);
        $method = PaymentMethod::tryFrom($value['paymentMethod']);
        $problems = [];
        if (1 !== preg_match('/^\d{1,3}$/', $value['dueDays']) || (int) $value['dueDays'] > self::MAX_DUE_DAYS) {
            $problems['dueDays'] = 'party.error.due_days';
        }
        if (!$currency instanceof Currency) {
            $problems['currency'] = 'party.error.choice';
        }
        if (!$language instanceof DocumentLanguage) {
            $problems['language'] = 'party.error.choice';
        }
        if (!$method instanceof PaymentMethod) {
            $problems['paymentMethod'] = 'party.error.choice';
        }
        if (false === $rate) {
            $problems['hourlyRate'] = 'party.error.hourly_rate';
        }
        if ([] !== $problems) {
            $parsed->errors[$scope] = $problems + ($parsed->errors[$scope] ?? []);

            return null;
        }
        \assert($currency instanceof Currency && $language instanceof DocumentLanguage && $method instanceof PaymentMethod && ($rate instanceof Money || null === $rate));

        return new InvoicingDefaults((int) $value['dueDays'], $currency, $language, $method, '' !== $value['reverseCharge'], '' !== $value['sendReminders'], $rate);
    }

    /**
     * @param array<mixed> $row
     *
     * @return list<InvoicingEmail>|null null when an address is wrong
     */
    private function emails(string $scope, array $row, FirmSettingsParsed $parsed): ?array
    {
        $emails = [];
        $valid = true;
        foreach (['invoiceEmails' => EmailPurpose::Invoices, 'copyEmails' => EmailPurpose::Copy] as $field => $purpose) {
            $text = $this->text($row[$field] ?? '');
            $parsed->values[$scope][$field] = $text;
            $addresses = preg_split('/[\s,;]+/', $text, -1, \PREG_SPLIT_NO_EMPTY);
            foreach (false === $addresses ? [] : $addresses as $address) {
                if (PartyEmail::isValid($address)) {
                    $emails[] = new InvoicingEmail($address, $purpose);
                } else {
                    $parsed->errors[$scope][$field] = 'party.error.email';
                    $valid = false;
                }
            }
        }

        return $valid ? $emails : null;
    }

    /**
     * @param array<mixed> $row
     */
    private function archived(string $scope, array $row, FirmSettingsParsed $parsed): bool
    {
        $archived = '1' === ($row['archived'] ?? null);
        $parsed->values[$scope]['archived'] = $archived ? '1' : '';

        return $archived;
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
