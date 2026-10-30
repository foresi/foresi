<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Http;

use App\Party\Application\PartyDetails;
use App\Party\Domain\Party;
use App\Party\Domain\PartyRole;

/**
 * The address book as CSV for spreadsheets: UTF-8 with a byte order mark so
 * Excel reads the accents, semicolons as separators, and no cell that a
 * spreadsheet would run as a formula.
 */
final readonly class PartyCsvExport
{
    private const string BOM = "\xEF\xBB\xBF";

    public function __construct(private PartyDetails $details)
    {
    }

    /**
     * @param list<Party>  $parties
     * @param list<string> $header  already translated column names
     */
    public function render(array $parties, array $header): string
    {
        $handle = fopen('php://temp', 'w+b');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open a temporary stream.');
        }
        fputcsv($handle, $this->safe($header), ';', '"', '', "\r\n");
        foreach ($parties as $party) {
            $billing = $this->details->billingAddress($party)?->address();
            fputcsv($handle, $this->safe([
                $party->displayName(),
                $party->kind()->value,
                implode(',', array_map(static fn (PartyRole $role): string => $role->value, $party->roles())),
                $party->registrationNumber()->value ?? '',
                $party->vatId()?->toString() ?? '',
                $this->details->generalEmail($party)?->address() ?? '',
                $party->phone(),
                $billing->street ?? '',
                $billing->city ?? '',
                $billing->postalCode ?? '',
                $billing->countryCode ?? '',
            ]), ';', '"', '', "\r\n");
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return self::BOM.$csv;
    }

    /**
     * @param list<string> $cells
     *
     * @return list<string>
     */
    private function safe(array $cells): array
    {
        return array_map(static fn (string $cell): string => 1 === preg_match('/^[=+\-@\t\r]/', $cell) ? "'".$cell : $cell, $cells);
    }
}
