<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Form;

use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\TotalRounding;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads and checks the invoicing forms of a firm before anything is written: an
 * expected mistake is a form error, never an exception out of a write. What needs
 * the stored data (a taken name, the counter) the controller checks.
 */
final readonly class FirmInvoicingReader
{
    /** The length limits of the columns; the texts are limited for one page of a document. */
    private const array MAX_LENGTHS = ['textBefore' => 2000, 'textAfter' => 2000, 'contactEmail' => 255, 'contactPhone' => 32, 'contactWeb' => 255];
    private const string CONSTANT_SYMBOL = '/^\d{1,10}$/D';
    private const string NEXT_NUMBER = '/^[1-9]\d{0,9}$/D';
    /** The largest value of the counter column. */
    private const int MAX_NEXT_NUMBER = 2_147_483_647;

    public function settings(Request $request): FirmInvoicingSubmission
    {
        $post = $request->request;
        $values = [
            'rounding' => $post->getString('rounding'),
            'constantSymbol' => trim($post->getString('constantSymbol')),
            'textBefore' => trim($post->getString('textBefore')),
            'textAfter' => trim($post->getString('textAfter')),
            'contactEmail' => trim($post->getString('contactEmail')),
            'contactPhone' => trim($post->getString('contactPhone')),
            'contactWeb' => trim($post->getString('contactWeb')),
        ];

        $errors = [];
        foreach (self::MAX_LENGTHS as $field => $max) {
            if (mb_strlen($values[$field]) > $max) {
                $errors[$field] = ['party.error.too_long'];
            }
        }
        if (!TotalRounding::tryFrom($values['rounding']) instanceof TotalRounding) {
            $errors['rounding'] = ['firm.invoicing.error.rounding'];
        }
        if ('' !== $values['constantSymbol'] && 1 !== preg_match(self::CONSTANT_SYMBOL, $values['constantSymbol'])) {
            $errors['constantSymbol'] = ['firm.invoicing.error.constant_symbol'];
        }
        if (!isset($errors['contactEmail']) && '' !== $values['contactEmail'] && false === filter_var($values['contactEmail'], \FILTER_VALIDATE_EMAIL)) {
            $errors['contactEmail'] = ['firm.invoicing.error.email'];
        }

        return new FirmInvoicingSubmission($values, $errors);
    }

    public function series(Request $request): FirmInvoicingSubmission
    {
        $post = $request->request;
        $values = [
            'name' => trim($post->getString('name')),
            'format' => trim($post->getString('format')),
            'nextNumber' => trim($post->getString('nextNumber')),
            'default' => $post->has('default') ? '1' : '',
        ];

        $errors = [];
        if ('' === $values['name']) {
            $errors['name'] = ['party.error.required'];
        } elseif (mb_strlen($values['name']) > Series::MAX_NAME_LENGTH) {
            $errors['name'] = ['party.error.too_long'];
        }
        if ('' !== $values['nextNumber'] && (1 !== preg_match(self::NEXT_NUMBER, $values['nextNumber']) || (int) $values['nextNumber'] > self::MAX_NEXT_NUMBER)) {
            $errors['nextNumber'] = ['series.error.next_number'];
        }

        return new FirmInvoicingSubmission($values, $errors);
    }
}
