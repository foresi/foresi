<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\Banking;

use App\Banking\Domain\MovementDirection;

/**
 * The words of a Komerční banka payment notice. Each notice states every payment
 * twice, in Czech and then in English, label above value.
 *
 * The outgoing heading and the fields come from a real notice. The incoming
 * heading is assumed to mirror the outgoing one until an incoming notice is at
 * hand; a different heading fails as an unknown layout, never as a guess.
 */
final class KbLabels
{
    public const string CZECH = 'cs';
    public const string ENGLISH = 'en';

    public const string AMOUNT = 'amount';
    public const string OWN_ACCOUNT = 'own account';
    public const string COUNTER_ACCOUNT = 'counter-account';
    public const string COUNTER_NAME = 'counter name';
    public const string DATE = 'date';
    public const string STATUS = 'status';
    public const string VARIABLE_SYMBOL = 'variable symbol';
    public const string CONSTANT_SYMBOL = 'constant symbol';
    public const string SPECIFIC_SYMBOL = 'specific symbol';
    public const string MESSAGE = 'message';

    /** The fields a payment cannot be recorded without. */
    public const array REQUIRED = [self::AMOUNT, self::OWN_ACCOUNT, self::STATUS];

    /** @var array<string, array{cs: string, en: string}> */
    private const array FIELDS = [
        self::AMOUNT => ['cs' => 'Částka a měna', 'en' => 'Amount and currency'],
        self::OWN_ACCOUNT => ['cs' => 'Číslo vašeho účtu', 'en' => 'Your account number'],
        self::COUNTER_ACCOUNT => ['cs' => 'Číslo protiúčtu', 'en' => 'Counteraccount number'],
        self::COUNTER_NAME => ['cs' => 'Jméno protiúčtu', 'en' => 'Counteraccount name'],
        self::DATE => ['cs' => 'Datum splatnosti', 'en' => 'Due date'],
        self::STATUS => ['cs' => 'Stav platby', 'en' => 'Status of the payment'],
        self::VARIABLE_SYMBOL => ['cs' => 'Variabilní symbol', 'en' => 'Variable symbol'],
        self::CONSTANT_SYMBOL => ['cs' => 'Konstantní symbol', 'en' => 'Constant symbol'],
        self::SPECIFIC_SYMBOL => ['cs' => 'Specifický symbol', 'en' => 'Specific symbol'],
        self::MESSAGE => ['cs' => 'Zpráva pro příjemce', 'en' => 'Message for beneficiary'],
    ];

    /** @var array<string, array{cs: string, en: string}> keyed by the MovementDirection value */
    private const array HEADINGS = [
        'incoming' => ['cs' => 'Příchozí platba', 'en' => 'Incoming payment'],
        'outgoing' => ['cs' => 'Odchozí platba', 'en' => 'Outgoing payment'],
    ];

    /** The status of a payment that is on the account. */
    private const array BOOKED = ['zaúčtováno', 'booked'];

    /**
     * The field a label names, or null for any other text.
     */
    public static function field(string $label): ?string
    {
        $label = self::normalized($label);
        foreach (self::FIELDS as $field => $labels) {
            if ($label === self::normalized($labels[self::CZECH]) || $label === self::normalized($labels[self::ENGLISH])) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The direction and language a heading line starts, or null when the line is no heading.
     *
     * @return array{MovementDirection, string}|null
     */
    public static function heading(string $line): ?array
    {
        $line = self::normalized($line);
        foreach (self::HEADINGS as $direction => $labels) {
            foreach ($labels as $language => $label) {
                if ($line === self::normalized($label)) {
                    return [MovementDirection::from($direction), $language];
                }
            }
        }

        return null;
    }

    public static function isBooked(string $status): bool
    {
        return \in_array(self::normalized($status), self::BOOKED, true);
    }

    /**
     * A label compares without case, surrounding space or a trailing colon.
     */
    private static function normalized(string $text): string
    {
        return mb_strtolower(trim(rtrim(trim($text), ':')), 'UTF-8');
    }
}
