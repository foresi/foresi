<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Form;

use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\InvoicingEmail;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyRole;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the scoped panels of the contact form. Only the firms it is given are
 * read; anything posted for another firm is ignored. A firm's panel counts only
 * when the form says whose values it holds, in firms[id][own][panel]: "1" for
 * the firm's own, empty for shared.
 *
 * @phpstan-import-type Values from FirmSettingsSubmission
 */
final readonly class FirmSettingsReader
{
    private const int MAX_DUE_DAYS = 365;

    /**
     * @param list<LegalEntity> $firms the active firms the form offered
     */
    public function read(Request $request, array $firms): FirmSettingsSubmission
    {
        $posted = $request->request->all('firms');
        $parsed = new FirmSettingsParsed();

        $shared = $posted[FirmSettingsSubmission::SHARED] ?? null;
        if (\is_array($shared)) {
            $this->shared($shared, $parsed);
        }
        foreach ($firms as $firm) {
            $row = $posted[$firm->id()] ?? null;
            if (\is_array($row) && \is_array($row['own'] ?? null)) {
                $this->firm($firm->id(), $row, $parsed);
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
     * @param array<mixed> $row
     */
    private function shared(array $row, FirmSettingsParsed $parsed): void
    {
        $scope = FirmSettingsSubmission::SHARED;
        if (\array_key_exists('dueDays', $row)) {
            $defaults = $this->defaults($scope, $row, $parsed);
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
        if (\array_key_exists('archived', $row)) {
            $parsed->archived[$scope] = $this->archived($scope, $row, $parsed);
        }
    }

    /**
     * @param array<mixed> $row
     */
    private function firm(string $id, array $row, FirmSettingsParsed $parsed): void
    {
        /** @var array<mixed> $own */
        $own = $row['own'];
        $is = static fn (string $panel): bool => '1' === ($own[$panel] ?? null);
        foreach (['roles', 'invoicing', 'emails', 'archive'] as $panel) {
            if (\array_key_exists($panel, $own)) {
                $parsed->own[$id][$panel] = $is($panel);
            }
        }

        if (\array_key_exists('roles', $own)) {
            $parsed->roles[$id] = $is('roles') ? $this->roles($id, $row, $parsed) : null;
        }
        if (\array_key_exists('invoicing', $own)) {
            $defaults = $is('invoicing') ? $this->defaults($id, $row, $parsed) : null;
            if ($defaults instanceof InvoicingDefaults || !$is('invoicing')) {
                $parsed->defaults[$id] = $defaults;
            }
        }
        if (\array_key_exists('emails', $own)) {
            $emails = $is('emails') ? $this->emails($id, $row, $parsed) : null;
            if (null !== $emails || !$is('emails')) {
                $parsed->emails[$id] = $emails;
            }
        }
        if (\array_key_exists('archive', $own)) {
            $parsed->archived[$id] = $is('archive') && $this->archived($id, $row, $parsed);
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
     * @param array<mixed> $row
     */
    private function defaults(string $scope, array $row, FirmSettingsParsed $parsed): ?InvoicingDefaults
    {
        $value = [
            'dueDays' => $this->text($row['dueDays'] ?? ''),
            'currency' => $this->text($row['currency'] ?? ''),
            'language' => $this->text($row['language'] ?? ''),
            'paymentMethod' => $this->text($row['paymentMethod'] ?? ''),
            'reverseCharge' => isset($row['reverseCharge']) ? '1' : '',
        ];
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
        if ([] !== $problems) {
            $parsed->errors[$scope] = $problems + ($parsed->errors[$scope] ?? []);

            return null;
        }
        \assert($currency instanceof Currency && $language instanceof DocumentLanguage && $method instanceof PaymentMethod);

        return new InvoicingDefaults((int) $value['dueDays'], $currency, $language, $method, '' !== $value['reverseCharge']);
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
