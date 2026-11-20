<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\Banking\CzechRepublic;

use Foresi\Banking\Contract\MovementDirection;

/**
 * The words of the Komerční banka payment notices. Each notice states every
 * payment twice, in Czech and then in English, label above value.
 *
 * KB mails two kinds of notice, each with labels of its own:
 *
 * - the notice of MojeBanka Business, "Oznámení o platbách": a heading per
 *   payment, "Příchozí platba" or "Odchozí platba", and a status field. The
 *   outgoing heading and the fields come from a real notice; the incoming
 *   heading is assumed to mirror the outgoing one.
 * - the notice of KB+, "Přijali jsme platbu na Váš účet": the heading carries
 *   the amount, the fields name the accounts "from" and "to", and there is no
 *   status and no counter-account name. All of it comes from a real incoming
 *   notice; KB+ is known to mail it only for incoming payments, which it has
 *   received and so are booked. Its English amount is written the English way,
 *   "48,176.00 CZK", and its English date month first, "11-20-2026".
 *
 * Any other heading fails as an unknown layout, never as a guess.
 */
final class KbLabels
{
    public const string CZECH = 'cs';
    public const string ENGLISH = 'en';

    /** The notice of MojeBanka Business, "Oznámení o platbách". */
    public const string NOTICE = 'notice';
    /** The notice of KB+, "Přijali jsme platbu na Váš účet". */
    public const string KB_PLUS = 'kb+';

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

    /** The status of a KB+ payment, which states none: the bank says it has received it. */
    public const string RECEIVED = 'received';

    /** The fields a payment cannot be recorded without, by layout. */
    public const array REQUIRED = [
        self::NOTICE => [self::AMOUNT, self::OWN_ACCOUNT, self::STATUS],
        self::KB_PLUS => [self::AMOUNT, self::OWN_ACCOUNT],
    ];

    /** @var array<string, array<string, array{cs: string, en: string}>> by layout */
    private const array FIELDS = [
        self::NOTICE => [
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
        ],
        // The accounts of an incoming payment: it comes from the counter-account to yours.
        self::KB_PLUS => [
            self::COUNTER_ACCOUNT => ['cs' => 'Z účtu', 'en' => 'From account'],
            self::OWN_ACCOUNT => ['cs' => 'Na účet', 'en' => 'To account'],
            self::DATE => ['cs' => 'Splatnost', 'en' => 'Due date'],
            self::VARIABLE_SYMBOL => ['cs' => 'Variabilní symbol', 'en' => 'Variable symbol'],
            self::CONSTANT_SYMBOL => ['cs' => 'Konstantní symbol', 'en' => 'Constant symbol'],
            self::SPECIFIC_SYMBOL => ['cs' => 'Specifický symbol', 'en' => 'Specific symbol'],
            self::MESSAGE => ['cs' => 'Zpráva pro příjemce', 'en' => 'Message for beneficiary'],
        ],
    ];

    /** @var array<string, array{cs: string, en: string}> keyed by the MovementDirection value */
    private const array HEADINGS = [
        'incoming' => ['cs' => 'Příchozí platba', 'en' => 'Incoming payment'],
        'outgoing' => ['cs' => 'Odchozí platba', 'en' => 'Outgoing payment'],
    ];

    /** The KB+ headings of an incoming payment, around its amount. */
    private const array KB_PLUS_HEADINGS = [
        self::CZECH => '/^Přijali jsme platbu (.+) na Váš účet$/iu',
        self::ENGLISH => '/^We have received the payment (.+) to your account$/iu',
    ];

    /** The first line of the KB+ footer, which ends the fields of a payment. */
    private const array FOOTERS = ['Chcete s něčím poradit?', 'We are here to help'];

    /** The status of a payment that is on the account. */
    private const array BOOKED = ['zaúčtováno', 'booked'];

    /**
     * The closed list of statuses known never to reach the account, which are
     * ignored. Any other status fails visibly, since it may be a payment that is
     * on the account after all. Rejected is on it deliberately: the bank refused
     * the payment, so no money moved.
     */
    private const array NOT_BOOKED = ['zamítnuto', 'rejected'];

    /**
     * The field a label names in the layout, or in any layout when none is
     * given; null for any other text.
     */
    public static function field(string $label, ?string $layout = null): ?string
    {
        $label = self::normalized($label);
        foreach (self::FIELDS as $of => $fields) {
            if (null !== $layout && $of !== $layout) {
                continue;
            }
            foreach ($fields as $field => $labels) {
                if ($label === self::normalized($labels[self::CZECH]) || $label === self::normalized($labels[self::ENGLISH])) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * The payment a heading line starts: its direction, language and layout, and
     * the amount when the heading carries it. Null when the line is no heading.
     *
     * @return array{direction: MovementDirection, language: string, layout: string, amount: string|null}|null
     */
    public static function heading(string $line): ?array
    {
        $normalized = self::normalized($line);
        foreach (self::HEADINGS as $direction => $labels) {
            foreach ($labels as $language => $label) {
                if ($normalized === self::normalized($label)) {
                    return ['direction' => MovementDirection::from($direction), 'language' => $language, 'layout' => self::NOTICE, 'amount' => null];
                }
            }
        }
        foreach (self::KB_PLUS_HEADINGS as $language => $pattern) {
            if (1 === preg_match($pattern, trim($line), $parts)) {
                return ['direction' => MovementDirection::Incoming, 'language' => $language, 'layout' => self::KB_PLUS, 'amount' => $parts[1]];
            }
        }

        return null;
    }

    /**
     * Whether the line starts the footer that follows the fields of a payment.
     */
    public static function footer(string $line): bool
    {
        $line = self::normalized($line);

        return array_any(self::FOOTERS, fn (string $footer): bool => $line === self::normalized($footer));
    }

    /**
     * Whether a payment with the status is on the account, or null for a status
     * on neither list.
     */
    public static function booked(string $status): ?bool
    {
        $status = self::normalized($status);

        return match (true) {
            \in_array($status, self::BOOKED, true) => true,
            \in_array($status, self::NOT_BOOKED, true) => false,
            default => null,
        };
    }

    /**
     * A label compares without case, surrounding space or a trailing colon.
     */
    private static function normalized(string $text): string
    {
        return mb_strtolower(trim(rtrim(trim($text), ':')), 'UTF-8');
    }
}
