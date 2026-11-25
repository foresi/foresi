<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\AiReader\Application;

/**
 * What the model is asked to find in a document: the header and the VAT recap,
 * no lines. A JSON Schema for structured output: every property is required and
 * may be null, no object takes another property, and the constraints the service
 * does not support (lengths, numbers) are left to the reader's own checks.
 */
final class ExtractionSchema
{
    /** The values of the kind of a received document. */
    public const array KINDS = ['tax_invoice', 'simplified_receipt', 'foreign_invoice', 'credit_note', 'advance_request', 'other'];

    /** The fields of the answer, in the order the schema lists them. */
    public const array FIELDS = [
        'kind', 'supplier_name', 'supplier_registration_number', 'supplier_vat_id', 'supplier_country',
        'supplier_number', 'variable_symbol', 'issue_date', 'tax_point_date', 'due_date', 'currency',
        'total', 'vat_recap', 'bank_account',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function fields(): array
    {
        $amount = 'a decimal string with a point and at most two decimals, positive, without a currency sign or a thousands separator (for example "1210.50")';
        $text = static fn (string $description): array => ['anyOf' => [['type' => 'string'], ['type' => 'null']], 'description' => $description];
        $date = static fn (string $description): array => ['anyOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'null']], 'description' => $description.' as YYYY-MM-DD.'];

        $properties = [
            'kind' => [
                'anyOf' => [['type' => 'string', 'enum' => self::KINDS], ['type' => 'null']],
                'description' => 'What the document is. A credit note subtracts, but its amounts stay positive.',
            ],
            'supplier_name' => $text('The name of the supplier: the party that issued the document, not the one it is addressed to.'),
            'supplier_registration_number' => $text('The company registration number of the supplier, as printed.'),
            'supplier_vat_id' => $text('The VAT identification number of the supplier, as printed, with its country prefix.'),
            'supplier_country' => $text('The country of the supplier as two capital letters (ISO 3166-1 alpha-2, for example "DE").'),
            'supplier_number' => $text('The number of the document as the supplier issued it.'),
            'variable_symbol' => $text('The variable symbol or payment reference printed on the document.'),
            'issue_date' => $date('The date the document was issued'),
            'tax_point_date' => $date('The date of the taxable supply'),
            'due_date' => $date('The date the payment is due'),
            'currency' => $text('The currency of the amounts as three capital letters (ISO 4217, for example "EUR").'),
            'total' => $text('The total amount to pay including VAT, as '.$amount.'.'),
            'vat_recap' => [
                'anyOf' => [
                    [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'rate' => ['type' => 'string', 'description' => 'The VAT rate as a percent number without a percent sign (for example "21" or "0").'],
                                'base' => ['type' => 'string', 'description' => 'The taxable base of this rate, as '.$amount.'.'],
                                'vat' => ['type' => 'string', 'description' => 'The VAT amount of this rate, as '.$amount.'.'],
                            ],
                            'required' => ['rate', 'base', 'vat'],
                            'additionalProperties' => false,
                        ],
                    ],
                    ['type' => 'null'],
                ],
                'description' => 'The VAT summary: one row per VAT rate. Null when the document prints none.',
            ],
            'bank_account' => $text('The bank account the supplier asks to be paid to: an IBAN, or a domestic number in the form prefix-number/bank code.'),
        ];

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }
}
