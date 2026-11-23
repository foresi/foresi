<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Domain;

use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * The rules of the lines of a received document. The lines split the VAT
 * recap: per rate they add up to the base of the recap row, in the document's
 * currency, and they name no rate the recap does not have. A document with an
 * empty recap (no VAT document, an advance request) has the one rate 0, whose
 * lines add up to its total, as the total is what it costs; without a total it
 * has nothing to split. Without stored lines a document has one implicit line
 * per rate.
 *
 * In CZK each line is its base at the document's rate, and the last line of a
 * rate takes the rounding difference, so the lines of a rate add up to the CZK
 * base of its recap row exactly.
 */
final class DocumentLines
{
    public const int MAX_LINES = 100;
    public const int MAX_TEXT_LENGTH = 255;
    private const string IMPLICIT_KEY = 'rate:';
    private const string NO_RECAP_RATE = '0';

    /**
     * The lines as the document keeps them: the text trimmed, the rate as the recap writes it.
     *
     * @param list<ReceivedDocumentLineInput> $lines
     *
     * @return list<ReceivedDocumentLineInput>
     *
     * @throws ReceivedDocumentRefused when the lines do not fit the recap
     */
    public static function check(array $lines, VatRecap $recap, ?Money $total, Currency $currency): array
    {
        if ([] === $lines) {
            return [];
        }
        if (\count($lines) > self::MAX_LINES) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::LINES_TOO_MANY);
        }
        $targets = self::targets($recap, $total, null);
        $sums = [];
        $checked = [];
        $ids = [];
        foreach ($lines as $line) {
            if (null !== $line->id) {
                if (\in_array($line->id, $ids, true)) {
                    throw new ReceivedDocumentRefused(ReceivedDocumentRefused::DUPLICATE_LINE_ID);
                }
                $ids[] = $line->id;
            }
            $text = trim($line->text);
            if ('' === $text) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::LINE_TEXT_REQUIRED);
            }
            if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::LINE_TEXT_TOO_LONG);
            }
            if ($line->base->currency() !== $currency) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::CURRENCY_MISMATCH);
            }
            $rate = VatRate::of($line->vatRate)->percent();
            $key = self::key($rate);
            if (!isset($targets[$key]) && (!$recap->isEmpty() || self::NO_RECAP_RATE !== $rate)) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::UNKNOWN_VAT_RATE);
            }
            $sums[$key] = ($sums[$key] ?? Money::ofMinor(0, $currency))->plus($line->base);
            $checked[] = new ReceivedDocumentLineInput($text, $line->base, $rate, $line->id);
        }
        // An empty recap without a total has no rate 0 to add up to.
        if ([] === $targets) {
            throw new ReceivedDocumentRefused(ReceivedDocumentRefused::LINES_DO_NOT_ADD_UP);
        }
        foreach ($targets as $key => $target) {
            if (!($sums[$key] ?? Money::ofMinor(0, $currency))->equals($target['base'])) {
                throw new ReceivedDocumentRefused(ReceivedDocumentRefused::LINES_DO_NOT_ADD_UP);
            }
        }

        return $checked;
    }

    /**
     * The base in CZK of each line, in their order; null while the recap row has none.
     *
     * @param list<ReceivedDocumentLineInput> $lines lines check() took
     *
     * @return list<?Money>
     */
    public static function czkBases(array $lines, VatRecap $recap, ?Money $total, ?ExchangeRate $rate): array
    {
        $targets = self::targets($recap, $total, $rate);
        $last = [];
        foreach ($lines as $index => $line) {
            $last[self::key($line->vatRate)] = $index;
        }
        $given = [];
        $bases = [];
        foreach ($lines as $index => $line) {
            $key = self::key($line->vatRate);
            $target = $targets[$key]['baseCzk'] ?? null;
            if (!$target instanceof Money || !$rate instanceof ExchangeRate) {
                $bases[] = null;
                continue;
            }
            $sofar = $given[$key] ?? Money::ofMinor(0, Currency::CZK);
            $czk = $last[$key] === $index ? $target->minus($sofar) : $rate->toCzk($line->base);
            $given[$key] = $sofar->plus($czk);
            $bases[] = $czk;
        }

        return $bases;
    }

    /**
     * The stored lines, or without them one implicit line per rate, its text the kind of the document.
     *
     * @param list<ReceivedDocumentLine> $stored
     *
     * @return list<LineView>
     */
    public static function views(array $stored, VatRecap $recap, ?Money $total, ?ExchangeRate $rate, ReceivedDocumentKind $kind): array
    {
        if ([] === $stored) {
            $views = [];
            foreach (self::targets($recap, $total, $rate) as $key => $target) {
                $views[] = new LineView($key, $kind->value, $target['base'], $target['baseCzk'], $target['rate'], true);
            }

            return $views;
        }
        $czk = self::czkBases(array_map(static fn (ReceivedDocumentLine $line): ReceivedDocumentLineInput => $line->toInput(), $stored), $recap, $total, $rate);

        return array_map(
            static fn (ReceivedDocumentLine $line, ?Money $baseCzk): LineView => new LineView($line->id(), $line->text(), $line->base(), $baseCzk, $line->vatRate(), false),
            $stored,
            $czk,
        );
    }

    /**
     * What the lines of each rate add up to, by the key of the rate: the rows
     * of the recap, or the total at rate 0.
     *
     * @return array<string, array{rate: string, base: Money, baseCzk: ?Money}>
     */
    private static function targets(VatRecap $recap, ?Money $total, ?ExchangeRate $rate): array
    {
        if ($recap->isEmpty()) {
            return $total instanceof Money ? [self::key(self::NO_RECAP_RATE) => ['rate' => self::NO_RECAP_RATE, 'base' => $total, 'baseCzk' => $rate?->toCzk($total)]] : [];
        }
        $targets = [];
        foreach ($recap->rows() as $row) {
            $targets[self::key($row->rate->percent())] = ['rate' => $row->rate->percent(), 'base' => $row->base, 'baseCzk' => $row->baseCzk];
        }

        return $targets;
    }

    /**
     * The key of the implicit line of a rate, which keys the rates here too: a
     * rate alone, such as 21, would turn into an integer key.
     */
    private static function key(string $rate): string
    {
        return self::IMPLICIT_KEY.$rate;
    }
}
