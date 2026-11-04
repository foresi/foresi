<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Country\CzechRepublic\Banking;

use App\Banking\Application\BankMessageParser;
use App\Banking\Application\InboundMail;
use App\Banking\Application\NotificationLines;
use App\Banking\Application\ParsedMovement;
use App\Banking\Application\ParseFailed;
use App\Banking\Application\ParseFailure;
use App\Banking\Application\ParseResult;
use App\Banking\Domain\MovementDirection;
use App\Country\CzechRepublic\Party\CzechBankAccount;
use App\Party\Domain\Iban;
use App\Party\Domain\InvalidIban;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;

/**
 * Reads the payment notices Komerční banka mails, "Oznámení o platbách".
 *
 * A notice states every payment once in Czech and once in English, and a mail
 * may carry an HTML and a text body. Each statement is read on its own and all
 * of them must agree, or the mail fails as inconsistent: a payment is recorded
 * from what the bank said in every place, never from a guess at one of them.
 * A payment whose status is on the closed not-booked list is ignored; a status
 * on neither list fails as an unknown layout.
 *
 * @phpstan-type Reading array{direction: MovementDirection, status: string, booked: bool, values: array<string, string|null>}
 */
final readonly class KbNotificationParser implements BankMessageParser
{
    private const array SENDER_DOMAINS = ['kb.cz', 'kbinfo.cz'];

    private const string AMOUNT = '/^(-)?\s*([0-9]{1,3}(?:[ .\x{00A0}\x{202F}][0-9]{3})*|[0-9]+)(?:,([0-9]{1,2}))?\s*([A-Z]{3})$/u';
    private const string DOMESTIC_ACCOUNT = '/^((?:[0-9]{1,6}-)?[0-9]{2,10})\/([0-9]{4})$/';
    private const string IBAN_SHAPE = '/^[A-Z]{2}[0-9]{2}[A-Z0-9 ]{11,}$/';
    private const string SYMBOL = '/^[0-9]{1,10}$/';
    private const string CZECH_DATE = '/^([0-9]{1,2})\.\s*([0-9]{1,2})\.\s*([0-9]{4})$/';
    private const string ISO_DATE = '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/';

    /** The IBAN a counter-account was derived to, kept beside it in a reading. */
    private const string COUNTER_IBAN = 'counter IBAN';

    public function __construct(private CzechBankAccount $accounts = new CzechBankAccount())
    {
    }

    public function id(): string
    {
        return 'cz.kb';
    }

    public function version(): int
    {
        return 1;
    }

    public function senderDomains(): array
    {
        return self::SENDER_DOMAINS;
    }

    /**
     * The bank's domain and any heading or field label of a notice. A notice whose
     * heading is unknown is still the bank's: it is supported and fails visibly.
     */
    public function supports(InboundMail $mail): bool
    {
        if (!$this->fromTheBank($mail->fromDomain())) {
            return false;
        }

        return array_any($this->parts($mail), fn ($lines): bool => $this->carriesLabels($lines));
    }

    public function parse(InboundMail $mail): ParseResult
    {
        $parts = [];
        foreach ($this->parts($mail) as $part => $lines) {
            if ($this->carriesLabels($lines)) {
                $parts[$part] = $this->readPart($lines);
            }
        }
        if ([] === $parts) {
            throw new ParseFailed(ParseFailure::UnknownLayout, 'The mail has no heading or field of a Komerční banka notice.');
        }
        $readings = array_shift($parts);
        foreach ($parts as $other) {
            $this->assertAgree($readings, $other, 'text and HTML parts');
        }

        $movements = [];
        $notBooked = null;
        foreach ($readings as $reading) {
            if ($reading['booked']) {
                $movements[] = $this->movement($reading, $mail);
            } else {
                $notBooked ??= $reading['status'];
            }
        }
        if ([] !== $movements) {
            return ParseResult::withMovements($movements);
        }
        if (null === $notBooked) {
            throw new ParseFailed(ParseFailure::UnknownLayout, 'The notice states no payment.');
        }

        return ParseResult::ignored('status:'.$notBooked);
    }

    private function fromTheBank(?string $domain): bool
    {
        if (null === $domain) {
            return false;
        }

        return array_any(self::SENDER_DOMAINS, fn (string $bank): bool => $domain === $bank || str_ends_with($domain, '.'.$bank));
    }

    /**
     * @return array<string, list<string>>
     */
    private function parts(InboundMail $mail): array
    {
        $parts = [];
        if (null !== $mail->html()) {
            $parts['html'] = NotificationLines::of($mail->html(), true);
        }
        if (null !== $mail->text()) {
            $parts['text'] = NotificationLines::of($mail->text(), false);
        }

        return $parts;
    }

    /**
     * @param list<string> $lines
     */
    private function carriesLabels(array $lines): bool
    {
        return array_any($lines, fn (string $line): bool => null !== KbLabels::heading($line) || null !== $this->label($line));
    }

    /**
     * The payments of one body, each stated in Czech, in English or in both.
     *
     * @param list<string> $lines
     *
     * @return list<Reading>
     */
    private function readPart(array $lines): array
    {
        $blocks = [];
        $current = null;
        foreach ($lines as $line) {
            $heading = KbLabels::heading($line);
            if (null !== $heading) {
                if (null !== $current) {
                    $blocks[] = $current;
                }
                $current = ['direction' => $heading[0], 'language' => $heading[1], 'lines' => []];
            } elseif (null !== $current) {
                $current['lines'][] = $line;
            } elseif (null !== $this->label($line)) {
                throw new ParseFailed(ParseFailure::UnknownLayout, 'A field of the notice stands under no heading the parser knows.');
            }
        }
        if (null === $current) {
            throw new ParseFailed(ParseFailure::UnknownLayout, 'The notice has fields but no heading the parser knows.');
        }
        $blocks[] = $current;

        $czech = [];
        $english = [];
        foreach ($blocks as $block) {
            $reading = $this->readBlock($block['direction'], $block['lines']);
            if (KbLabels::CZECH === $block['language']) {
                $czech[] = $reading;
            } else {
                $english[] = $reading;
            }
        }
        // A notice in one language only is read as it stands.
        $hasCzech = [] !== $czech;
        $hasEnglish = [] !== $english;
        if (!$hasCzech || !$hasEnglish) {
            return [...$czech, ...$english];
        }

        $this->assertAgree($czech, $english, 'Czech and English parts');
        foreach ($czech as $index => $reading) {
            // A field only one language states still counts.
            $czech[$index]['values'] += $english[$index]['values'];
        }

        return $czech;
    }

    /**
     * @param list<string> $lines
     *
     * @return Reading
     */
    private function readBlock(MovementDirection $direction, array $lines): array
    {
        $raw = [];
        $count = \count($lines);
        for ($i = 0; $i < $count; ++$i) {
            $label = $this->label($lines[$i]);
            if (null === $label) {
                continue;
            }
            [$field, $value] = $label;
            if (null === $value) {
                $next = $lines[$i + 1] ?? null;
                $value = '';
                if (null !== $next && null === $this->label($next)) {
                    $value = $next;
                    ++$i;
                }
            }
            if (\array_key_exists($field, $raw) && $raw[$field] !== $value) {
                throw new ParseFailed(ParseFailure::Ambiguous, \sprintf('The %s is stated twice for one payment.', $field));
            }
            $raw[$field] = $value;
        }

        foreach (KbLabels::REQUIRED as $field) {
            if ('' === ($raw[$field] ?? '')) {
                throw new ParseFailed(ParseFailure::MissingField, \sprintf('A payment has no %s.', $field));
            }
        }

        $status = $raw[KbLabels::STATUS];
        $values = [
            KbLabels::AMOUNT => $this->amount($raw[KbLabels::AMOUNT], $direction),
            KbLabels::OWN_ACCOUNT => $this->ownAccount($raw[KbLabels::OWN_ACCOUNT]),
        ];
        if (\array_key_exists(KbLabels::COUNTER_ACCOUNT, $raw)) {
            [$values[KbLabels::COUNTER_ACCOUNT], $values[self::COUNTER_IBAN]] = $this->counterAccount($raw[KbLabels::COUNTER_ACCOUNT]);
        }
        foreach ([KbLabels::COUNTER_NAME, KbLabels::MESSAGE] as $field) {
            if (\array_key_exists($field, $raw)) {
                $values[$field] = '' === $raw[$field] ? null : $raw[$field];
            }
        }
        foreach ([KbLabels::VARIABLE_SYMBOL, KbLabels::CONSTANT_SYMBOL, KbLabels::SPECIFIC_SYMBOL] as $field) {
            if (\array_key_exists($field, $raw)) {
                $values[$field] = $this->symbol($raw[$field], $field);
            }
        }
        if ('' !== ($raw[KbLabels::DATE] ?? '')) {
            $values[KbLabels::DATE] = $this->date($raw[KbLabels::DATE]);
        }

        $booked = KbLabels::booked($status);
        if (null === $booked) {
            throw new ParseFailed(ParseFailure::UnknownLayout, \sprintf('The payment status "%s" is not known.', $status));
        }

        return ['direction' => $direction, 'status' => $status, 'booked' => $booked, 'values' => $values];
    }

    /**
     * The field a line labels, with the value written after a colon on the same
     * line, or a null value when the value is on the next line.
     *
     * @return array{string, string|null}|null
     */
    private function label(string $line): ?array
    {
        $field = KbLabels::field($line);
        if (null !== $field) {
            return [$field, null];
        }
        $colon = strpos($line, ':');
        if (false === $colon) {
            return null;
        }
        $field = KbLabels::field(substr($line, 0, $colon));
        if (null === $field) {
            return null;
        }
        $value = trim(substr($line, $colon + 1));

        return [$field, '' === $value ? null : $value];
    }

    /**
     * Each statement must name as many payments, and each payment must agree on
     * every field both statements give.
     *
     * @param list<Reading> $first
     * @param list<Reading> $second
     */
    private function assertAgree(array $first, array $second, string $what): void
    {
        if (\count($first) !== \count($second)) {
            throw new ParseFailed(ParseFailure::Inconsistent, \sprintf('The %s list a different number of payments.', $what));
        }
        foreach ($first as $index => $one) {
            $other = $second[$index];
            if ($one['direction'] !== $other['direction']) {
                throw new ParseFailed(ParseFailure::Inconsistent, \sprintf('The %s disagree on the direction of a payment.', $what));
            }
            if ($one['booked'] !== $other['booked']) {
                throw new ParseFailed(ParseFailure::Inconsistent, \sprintf('The %s disagree on the status of a payment.', $what));
            }
            $bothIbans = null !== ($one['values'][self::COUNTER_IBAN] ?? null) && null !== ($other['values'][self::COUNTER_IBAN] ?? null);
            foreach (array_intersect_key($one['values'], $other['values']) as $field => $value) {
                // The same account may be written domestically in one place and as an IBAN in another.
                if (KbLabels::COUNTER_ACCOUNT === $field && $bothIbans) {
                    continue;
                }
                if ($value !== $other['values'][$field]) {
                    throw new ParseFailed(ParseFailure::Inconsistent, \sprintf('The %s disagree on the %s.', $what, KbLabels::COUNTER_ACCOUNT === $field || self::COUNTER_IBAN === $field ? KbLabels::COUNTER_ACCOUNT : $field));
                }
            }
        }
    }

    /**
     * @param Reading $reading
     */
    private function movement(array $reading, InboundMail $mail): ParsedMovement
    {
        $values = $reading['values'];
        [$minor, $currency] = explode(' ', (string) $values[KbLabels::AMOUNT]);

        return new ParsedMovement(
            direction: $reading['direction'],
            amount: Money::ofMinor((int) $minor, Currency::from($currency)),
            ownAccount: (string) $values[KbLabels::OWN_ACCOUNT],
            counterAccount: $values[KbLabels::COUNTER_ACCOUNT] ?? null,
            counterIban: $values[self::COUNTER_IBAN] ?? null,
            counterName: $values[KbLabels::COUNTER_NAME] ?? null,
            bookedOn: $this->bookedOn($values[KbLabels::DATE] ?? null, $mail),
            variableSymbol: $values[KbLabels::VARIABLE_SYMBOL] ?? null,
            constantSymbol: $values[KbLabels::CONSTANT_SYMBOL] ?? null,
            specificSymbol: $values[KbLabels::SPECIFIC_SYMBOL] ?? null,
            message: $values[KbLabels::MESSAGE] ?? null,
            bankReference: null,
        );
    }

    /**
     * A comma is the decimal point; spaces, no-break spaces and dots group the
     * thousands, in threes. Anything else is not read rather than read wrong.
     *
     * @return string the minor units and the currency code, "15000 CZK"
     */
    private function amount(string $written, MovementDirection $direction): string
    {
        if (1 !== preg_match(self::AMOUNT, $written, $parts)) {
            throw new ParseFailed(ParseFailure::InvalidValue, 'The amount is not written as an amount and a currency.');
        }
        $currency = Currency::tryFrom($parts[4]);
        if (null === $currency) {
            throw new ParseFailed(ParseFailure::InvalidValue, 'The amount is in a currency Foresi does not support.');
        }
        if ('-' === $parts[1] && MovementDirection::Incoming === $direction) {
            throw new ParseFailed(ParseFailure::Inconsistent, 'The amount of an incoming payment is negative.');
        }
        $whole = (string) preg_replace('/[^0-9]/', '', $parts[2]);
        $fraction = '' === $parts[3] ? '0' : $parts[3];
        try {
            $amount = Money::of($whole.'.'.$fraction, $currency);
        } catch (\InvalidArgumentException|\OverflowException) {
            throw new ParseFailed(ParseFailure::InvalidValue, 'The amount is out of range.');
        }
        if ($amount->isZero()) {
            throw new ParseFailed(ParseFailure::InvalidValue, 'The amount is zero.');
        }

        return $amount->minor().' '.$currency->value;
    }

    private function ownAccount(string $written): string
    {
        $iban = $this->iban($written, KbLabels::OWN_ACCOUNT);
        if (null === $iban) {
            throw new ParseFailed(ParseFailure::InvalidValue, 'The own account is neither a Czech account number nor an IBAN.');
        }

        return $iban;
    }

    /**
     * A foreign account that is no IBAN is kept as written, without one.
     *
     * @return array{string|null, string|null} as written, and its IBAN
     */
    private function counterAccount(string $written): array
    {
        if ('' === $written) {
            return [null, null];
        }

        return [$written, $this->iban($written, KbLabels::COUNTER_ACCOUNT)];
    }

    /**
     * The IBAN of a domestic "prefix-number/code" or of an IBAN; null for anything else.
     */
    private function iban(string $written, string $field): ?string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $written));
        try {
            if (1 === preg_match(self::DOMESTIC_ACCOUNT, $compact, $parts)) {
                return $this->accounts->toIban($parts[1], $parts[2])->toString();
            }
            if (str_contains($compact, '/')) {
                throw new InvalidIban('Not a Czech account number.');
            }
            if (1 === preg_match(self::IBAN_SHAPE, strtoupper(trim($written)))) {
                return Iban::fromString($compact)->toString();
            }
        } catch (InvalidIban) {
            throw new ParseFailed(ParseFailure::InvalidValue, \sprintf('The %s is not a valid account number.', $field));
        }

        return null;
    }

    private function symbol(string $written, string $field): ?string
    {
        if ('' === $written || '0' === $written) {
            return null;
        }
        if (1 !== preg_match(self::SYMBOL, $written)) {
            throw new ParseFailed(ParseFailure::InvalidValue, \sprintf('The %s is not up to 10 digits.', $field));
        }

        return $written;
    }

    /**
     * @return string the date as Y-m-d
     */
    private function date(string $written): string
    {
        if (1 === preg_match(self::CZECH_DATE, $written, $parts)) {
            [, $day, $month, $year] = $parts;
        } elseif (1 === preg_match(self::ISO_DATE, $written, $parts)) {
            [, $year, $month, $day] = $parts;
        } else {
            throw new ParseFailed(ParseFailure::InvalidValue, 'The date is not written as d.m.yyyy or yyyy-mm-dd.');
        }
        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            throw new ParseFailed(ParseFailure::InvalidValue, 'The date does not exist.');
        }

        return \sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * The due date the notice gives, or else the day the mail was sent, in Prague.
     */
    private function bookedOn(?string $date, InboundMail $mail): \DateTimeImmutable
    {
        $prague = new \DateTimeZone('Europe/Prague');
        if (null === $date) {
            $sent = $mail->date() ?? throw new ParseFailed(ParseFailure::MissingField, 'A payment has no date, and neither has the mail.');
            $date = $sent->setTimezone($prague)->format('Y-m-d');
        }

        return new \DateTimeImmutable($date.' 00:00:00', $prague);
    }
}
