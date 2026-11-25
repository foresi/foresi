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
        $text = static fn (): array => ['anyOf' => [['type' => 'string'], ['type' => 'null']]];
        $date = static fn (): array => ['anyOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'null']]];

        $properties = [
            'kind' => ['anyOf' => [['type' => 'string', 'enum' => self::KINDS], ['type' => 'null']]],
            'supplier_name' => $text(),
            'supplier_registration_number' => $text(),
            'supplier_vat_id' => $text(),
            'supplier_country' => $text(),
            'supplier_number' => $text(),
            'variable_symbol' => $text(),
            'issue_date' => $date(),
            'tax_point_date' => $date(),
            'due_date' => $date(),
            'currency' => $text(),
            'total' => $text(),
            'vat_recap' => ['anyOf' => [
                [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['rate' => ['type' => 'string'], 'base' => ['type' => 'string'], 'vat' => ['type' => 'string']],
                        'required' => ['rate', 'base', 'vat'],
                        'additionalProperties' => false,
                    ],
                ],
                ['type' => 'null'],
            ]],
            'bank_account' => $text(),
        ];

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }
}
