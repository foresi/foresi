<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Import;

use App\Import\Domain\Preset\AbstractSourcePreset;

/**
 * The contact export of iDoklad (UTF-8 with BOM, comma separated, one header
 * row). The user picks which columns to export, in any order, so the export is
 * recognised by any four of its headers. Titul, Jméno and Příjmení name the
 * contact person; Sleva and the reminder setting have no field yet and are
 * left out.
 */
final class IdokladContactPreset extends AbstractSourcePreset
{
    public const string KEY = 'idoklad_contacts';

    private const int RECOGNISED_HEADERS = 4;

    public function key(): string
    {
        return self::KEY;
    }

    public function labelKey(): string
    {
        return 'import.preset.idoklad_contacts';
    }

    public function targetKey(): string
    {
        return PartyImportTarget::KEY;
    }

    public function columns(): array
    {
        return [
            'Firma' => 'display_name',
            'IČ' => 'registration_number',
            'DIČ' => 'vat_id',
            'Ulice' => 'billing_street',
            'PSČ' => 'billing_postal_code',
            'Město' => 'billing_city',
            'Stát' => 'billing_country',
            'E-mailová adresa' => 'email',
            'Další příjemci' => 'copy_email',
            'Telefon' => 'phone',
            'www' => 'website',
            'Sleva' => null,
            'Splatnost' => 'due_days',
            'Nastavení odesílaní upomínek' => null,
            'Titul' => 'contact_title',
            'Jméno' => 'contact_first_name',
            'Příjmení' => 'contact_last_name',
            'Mobil' => 'mobile',
            'Číslo účtu' => 'bank_account_number',
            'Kód banky' => 'bank_code',
            'IBAN' => 'bank_account_iban',
            'SWIFT' => 'bank_account_bic',
        ];
    }

    public function values(): array
    {
        // iDoklad writes the Czech names of the countries; the official long forms are not ICU display names.
        $countries = ['Česká republika' => 'CZ', 'Slovenská republika' => 'SK'];

        return ['billing_country' => $countries];
    }

    /**
     * Any four of the iDoklad headers, whatever else the file has. Fewer are
     * too common to tell iDoklad apart; their names still match by themselves.
     */
    protected function isRecognised(int $known, int $total): bool
    {
        return $known >= self::RECOGNISED_HEADERS;
    }
}
