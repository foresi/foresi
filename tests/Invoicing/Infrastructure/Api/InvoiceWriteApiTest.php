<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Api;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Shared\Infrastructure\Operation\InputSchema;
use Foresi\Shared\Infrastructure\Operation\Operations;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * Sales invoices written over REST and MCP: issued with the defaults and the
 * counter of the form, changed with its rules and locks, trashed and restored, marked sent,
 * behind invoices:write.
 */
final class InvoiceWriteApiTest extends ApiTestCase
{
    private string $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('invoices:write')]);
    }

    public function testAReadTokenCannotWrite(): void
    {
        $reader = $this->issued([ApiScope::from('invoices:read')]);

        $this->send('POST', '/api/v1/invoices', $this->fields(), $reader);
        $this->assertProblem(403, 'insufficient_scope');
        $this->get('/api/v1/invoices', secret: $reader);
        self::assertSame([], $this->ids());
    }

    public function testTheFieldsDeclareTheLengthsTheDocumentHolds(): void
    {
        $texts = ['bank_account' => 34, 'variable_symbol' => 10, 'constant_symbol' => Invoice::MAX_CONSTANT_SYMBOL_LENGTH, 'order_number' => Invoice::MAX_ORDER_NUMBER_LENGTH, 'description' => 2000, 'text_before' => 2000, 'text_after' => 2000];

        self::assertSame(['number' => Invoice::MAX_NUMBER_LENGTH] + $texts, $this->lengths('invoices.create'));
        self::assertSame(['number' => Invoice::MAX_NUMBER_LENGTH] + $texts, $this->lengths('invoices.update'));
    }

    /**
     * @return array<string, int> the longest value of each field that declares one, in the order of the fields
     */
    private function lengths(string $operation): array
    {
        $properties = InputSchema::of(self::getContainer()->get(Operations::class)->get($operation)->fields)['properties'];
        self::assertIsArray($properties);
        $lengths = [];
        foreach ($properties as $wire => $property) {
            if (\is_string($wire) && \is_array($property) && isset($property['maxLength']) && \is_int($property['maxLength'])) {
                $lengths[$wire] = $property['maxLength'];
            }
        }

        return $lengths;
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(): array
    {
        return [
            'customer_id' => $this->customerId,
            'issue_date' => '2026-11-02',
            'lines' => [['text' => 'Consulting', 'unit_price' => '1000.00', 'quantity' => '2', 'unit' => 'h']],
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null, ?string $secret = null, ?string $idempotencyKey = null): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ] + (null === $idempotencyKey ? [] : ['HTTP_IDEMPOTENCY_KEY' => $idempotencyKey]), content: null === $body ? null : json_encode((object) $body, \JSON_THROW_ON_ERROR));
    }
}
