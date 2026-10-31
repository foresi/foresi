<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Form;

use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\InvoicingDefaults;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\PaymentMethod;
use App\Shared\Domain\Currency;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the per-firm sections of the contact form. Only the firms it is given
 * are read; anything posted for another firm is ignored.
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
        $values = [];
        $errors = [];
        $settings = [];
        foreach ($firms as $firm) {
            $row = $posted[$firm->id()] ?? null;
            if (!\is_array($row)) {
                continue;
            }
            $value = [
                'dueDays' => $this->text($row['dueDays'] ?? ''),
                'currency' => $this->text($row['currency'] ?? ''),
                'language' => $this->text($row['language'] ?? ''),
                'paymentMethod' => $this->text($row['paymentMethod'] ?? ''),
                'reverseCharge' => isset($row['reverseCharge']) ? '1' : '',
            ];
            $values[$firm->id()] = $value;

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
                $errors[$firm->id()] = $problems;

                continue;
            }
            \assert($currency instanceof Currency && $language instanceof DocumentLanguage && $method instanceof PaymentMethod);
            $settings[$firm->id()] = new InvoicingDefaults((int) $value['dueDays'], $currency, $language, $method, '' !== $value['reverseCharge']);
        }

        return new FirmSettingsSubmission($values, $errors, $settings);
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
