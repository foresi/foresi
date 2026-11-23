<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Api;

use Foresi\Invoicing\Application\Api\CreateRecurringInvoiceInput;
use Foresi\Invoicing\Application\Api\InvoicingRefused;
use Foresi\Invoicing\Application\Api\RecurringWrites;
use Foresi\Shared\Infrastructure\Operation\InputMapper;
use Foresi\Shared\Infrastructure\Operation\InputSchema;
use Foresi\Shared\Infrastructure\Operation\InvalidInput;
use Foresi\Shared\Infrastructure\Operation\Operations;

/**
 * A recurring invoice previewed from the fields of a create, read as the create
 * operation reads them, for REST and MCP alike. Nothing is saved.
 */
final readonly class RecurringInvoicePreviews
{
    /** The operation whose fields a preview takes. */
    public const string CREATE = 'recurring-invoices.create';
    public const int MAX = 24;

    public function __construct(private Operations $operations, private RecurringWrites $writes, private RecurringInvoiceJson $json)
    {
    }

    /**
     * @param array<mixed> $sent the fields of a create by wire name, and count
     *
     * @return array<string, mixed>
     *
     * @throws InvalidInput
     * @throws InvoicingRefused
     */
    public function of(array $sent): array
    {
        $count = $sent['count'] ?? RecurringInvoiceJson::NEXT;
        unset($sent['count']);
        if (!\is_int($count) || $count < 1 || $count > self::MAX) {
            throw new InvalidInput('count', \sprintf('must be a whole number from 1 to %d.', self::MAX));
        }
        $input = InputMapper::map($this->operations->get(self::CREATE), $sent);
        \assert($input instanceof CreateRecurringInvoiceInput);

        return $this->json->draft($this->writes->preview($input, $count));
    }

    /**
     * The JSON schema of what of() takes: the create's, and count.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        $schema = InputSchema::of($this->operations->get(self::CREATE)->fields);
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $properties['count'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX, 'description' => \sprintf('How many next issues to show, 1 to %d; %d when left out.', self::MAX, RecurringInvoiceJson::NEXT)];
        $schema['properties'] = $properties;

        return $schema;
    }
}
