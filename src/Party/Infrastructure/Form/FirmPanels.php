<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Form;

use App\Party\Application\ContactSettingsForFirm;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyRole;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the template needs for the panels of the contact form that have a firm
 * scope: every firm's values are in the page, so switching takes no request. A
 * firm without its own values holds the shared ones, read only.
 *
 * @phpstan-import-type Values from FirmSettingsSubmission
 *
 * @phpstan-type Scope array{id: string, name: string, shared: bool, own: bool, values: Values, errors: array<string, string>}
 * @phpstan-type Panel array{key: string, active: string, scopes: list<Scope>}
 */
final readonly class FirmPanels
{
    public function __construct(private ContactSettingsForFirm $settings, private TranslatorInterface $translator)
    {
    }

    /**
     * @param array<string, LegalEntity> $firms       the active firms by id, at least two
     * @param list<string>               $sharedRoles the roles as the form shows them
     *
     * @return array<string, Panel> by panel
     */
    public function build(?Party $party, array $firms, array $sharedRoles, ?FirmSettingsSubmission $submission): array
    {
        $shared = $this->sharedValues($party, $sharedRoles, $submission);

        $panels = [];
        foreach ($party instanceof Party ? ['roles', 'invoicing', 'emails', 'archive'] : ['roles', 'invoicing', 'emails'] as $panel) {
            $scopes = [[
                'id' => FirmSettingsSubmission::SHARED,
                'name' => $this->translator->trans('party.scope.all_firms'),
                'shared' => true,
                'own' => false,
                'values' => $shared,
                'errors' => $this->errors(FirmSettingsSubmission::SHARED, $panel, $submission),
            ]];
            foreach ($firms as $id => $firm) {
                $own = $submission?->own[$id][$panel] ?? $this->hasOwn($party, $firm, $panel);
                $scopes[] = [
                    'id' => $id,
                    'name' => $firm->name(),
                    'shared' => false,
                    'own' => $own,
                    'values' => $own ? ($submission?->values[$id] ?? []) + $this->storedValues($party, $firm, $panel) + $shared : $shared,
                    'errors' => $this->errors($id, $panel, $submission),
                ];
            }
            $panels[$panel] = ['key' => $panel, 'active' => $this->active($panel, $scopes, $submission), 'scopes' => $scopes];
        }

        return $panels;
    }

    /**
     * @param list<Scope> $scopes
     */
    private function active(string $panel, array $scopes, ?FirmSettingsSubmission $submission): string
    {
        foreach ($scopes as $scope) {
            if ([] !== $scope['errors']) {
                return $scope['id'];
            }
        }
        foreach ($scopes as $scope) {
            if ($scope['id'] === ($submission?->active[$panel] ?? null)) {
                return $scope['id'];
            }
        }

        return FirmSettingsSubmission::SHARED;
    }

    private function hasOwn(?Party $party, LegalEntity $firm, string $panel): bool
    {
        if (!$party instanceof Party) {
            return false;
        }

        return match ($panel) {
            'roles' => $this->settings->hasOwnRoles($party, $firm),
            'invoicing' => $this->settings->hasOwnDefaults($party, $firm),
            'emails' => $this->settings->hasOwnEmails($party, $firm),
            default => $this->settings->hasOwnArchive($party, $firm),
        };
    }

    /**
     * @return Values
     */
    private function storedValues(?Party $party, LegalEntity $firm, string $panel): array
    {
        if (!$party instanceof Party) {
            return [];
        }

        return match ($panel) {
            'roles' => ['roles' => $this->roleValues($this->settings->rolesFor($party, $firm))],
            'invoicing' => $this->defaultsValues($this->settings->defaultsFor($party, $firm)),
            'emails' => $this->emailValues($this->settings->emailsFor($party, $firm)),
            default => ['archived' => $this->settings->hasOwnArchive($party, $firm) ? '1' : ''],
        };
    }

    /**
     * @param list<string> $sharedRoles
     *
     * @return Values
     */
    private function sharedValues(?Party $party, array $sharedRoles, ?FirmSettingsSubmission $submission): array
    {
        $stored = ['roles' => $sharedRoles]
            + $this->defaultsValues($party?->invoicingDefaults() ?? InvoicingDefaults::standard())
            + $this->emailValues($party instanceof Party ? $this->settings->sharedEmails($party) : [])
            + ['archived' => true === $party?->isArchived() ? '1' : ''];

        return ($submission?->values[FirmSettingsSubmission::SHARED] ?? []) + $stored;
    }

    /**
     * @param list<PartyRole> $roles
     *
     * @return list<string>
     */
    private function roleValues(array $roles): array
    {
        return array_map(static fn (PartyRole $role): string => $role->value, $roles);
    }

    /**
     * @return array<string, string>
     */
    private function defaultsValues(InvoicingDefaults $defaults): array
    {
        return [
            'dueDays' => (string) $defaults->dueDays,
            'currency' => $defaults->currency->value,
            'language' => $defaults->language->value,
            'paymentMethod' => $defaults->paymentMethod->value,
            'reverseCharge' => $defaults->reverseCharge ? '1' : '',
        ];
    }

    /**
     * @param list<PartyEmail> $emails
     *
     * @return array<string, string>
     */
    private function emailValues(array $emails): array
    {
        $addresses = static fn (EmailPurpose $purpose): string => implode("\n", array_map(
            static fn (PartyEmail $email): string => $email->address(),
            array_filter($emails, static fn (PartyEmail $email): bool => $purpose === $email->purpose()),
        ));

        return ['invoiceEmails' => $addresses(EmailPurpose::Invoices), 'copyEmails' => $addresses(EmailPurpose::Copy)];
    }

    /**
     * @return array<string, string>
     */
    private function errors(string $scope, string $panel, ?FirmSettingsSubmission $submission): array
    {
        $fields = match ($panel) {
            'invoicing' => ['dueDays', 'currency', 'language', 'paymentMethod'],
            'emails' => ['invoiceEmails', 'copyEmails'],
            default => [],
        };
        $messages = [];
        foreach ($fields as $field) {
            $key = $submission?->errors[$scope][$field] ?? null;
            if (null !== $key) {
                $messages[$field] = $this->translator->trans($key);
            }
        }

        return $messages;
    }
}
