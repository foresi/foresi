<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Country\Integration\Purchasing\CzechRepublic;

use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\Reading;
use Foresi\Purchasing\Contract\Reading\ReadValue;

/**
 * Reads the Czech short payment descriptor (SPD, "SPAYD") that a QR payment
 * code carries: `SPD*1.0*ACC:<IBAN>[+<BIC>]*AM:<amount>*CC:<currency>*X-VS:<variable symbol>*DT:<YYYYMMDD>`.
 * Keys it does not use are ignored and a value that does not fit its key is
 * skipped; the text is data and never more than that.
 */
final class SpaydParser
{
    private const string SOURCE = 'payment_qr';

    public function parse(string $text): ?Reading
    {
        $parts = explode('*', trim($text));
        if ('SPD' !== array_shift($parts)) {
            return null;
        }
        $keys = [];
        foreach ($parts as $part) {
            $separator = strpos($part, ':');
            if (false === $separator) {
                continue;
            }
            $key = strtoupper(substr($part, 0, $separator));
            $keys[$key] ??= rawurldecode(substr($part, $separator + 1));
        }

        // An amount without a currency we trust is not safe to propose.
        $currency = $this->currency($keys['CC'] ?? null);
        $values = array_filter([
            $this->account($keys['ACC'] ?? null),
            null === $currency ? null : $this->total($keys['AM'] ?? null),
            $this->variableSymbol($keys['X-VS'] ?? null),
            $this->dueDate($keys['DT'] ?? null),
        ]);
        if ([] === $values) {
            return null;
        }
        if (null !== $currency) {
            $values[] = new ReadValue(ReadField::Currency, $currency, self::SOURCE);
        }

        return new Reading($values);
    }

    private function account(?string $value): ?ReadValue
    {
        $iban = strtoupper(explode('+', (string) $value)[0]);

        return 1 === preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban) ? new ReadValue(ReadField::BankAccount, $iban, self::SOURCE) : null;
    }

    /**
     * Minor units from an amount with at most two decimals, without floats.
     */
    private function total(?string $value): ?ReadValue
    {
        if (null === $value || 1 !== preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', $value, $found)) {
            return null;
        }

        $minor = (int) $found[1] * 100 + (int) str_pad($found[2] ?? '', 2, '0');

        return $minor > 0 ? new ReadValue(ReadField::Total, $minor, self::SOURCE) : null;
    }

    /**
     * CZK when the key is absent; null when it is present and not a currency code.
     */
    private function currency(?string $value): ?string
    {
        if (null === $value) {
            return 'CZK';
        }
        $code = strtoupper($value);

        return 1 === preg_match('/^[A-Z]{3}$/', $code) ? $code : null;
    }

    private function variableSymbol(?string $value): ?ReadValue
    {
        return null !== $value && 1 === preg_match('/^\d{1,10}$/', $value) ? new ReadValue(ReadField::VariableSymbol, $value, self::SOURCE) : null;
    }

    private function dueDate(?string $value): ?ReadValue
    {
        if (null === $value || 1 !== preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $found) || !checkdate((int) $found[2], (int) $found[3], (int) $found[1])) {
            return null;
        }

        return new ReadValue(ReadField::DueDate, \sprintf('%s-%s-%s', $found[1], $found[2], $found[3]), self::SOURCE);
    }
}
