<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Api;

use Foresi\Shared\Infrastructure\Api\Cursor;
use Foresi\Tests\Invoicing\Support\IssuesInvoices;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET /api/v1/invoices and /api/v1/invoices/{id}: the documents of the token's
 * organisation, narrowed by the query string and read by cursor.
 */
final class InvoiceApiTest extends ApiTestCase
{
    use IssuesInvoices;

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'a cursor that is not base64url' => [['cursor' => '!!!'], 'cursor'];
        yield 'a cursor that is no id' => [['cursor' => Cursor::encode('not-an-id')], 'cursor'];
        yield 'a cursor with a space' => [['cursor' => ' '.Cursor::encode('01963c5e-0000-7000-8000-000000000001')], 'cursor'];
        yield 'a limit above the maximum' => [['limit' => '101'], 'limit'];
        yield 'a limit below one' => [['limit' => '0'], 'limit'];
        yield 'a limit that is no number' => [['limit' => 'many'], 'limit'];
        yield 'an unknown kind' => [['kind' => 'receipt'], 'kind'];
        yield 'an unknown state' => [['state' => 'cancelled'], 'state'];
        yield 'a retired firm' => [['firm_id' => 'nope'], 'firm_id'];
        yield 'a customer that is no id' => [['customer_id' => 'nope'], 'customer_id'];
        yield 'a trash flag that is no boolean' => [['trashed' => 'maybe'], 'trashed'];
        yield 'a first day that is no day' => [['issued_from' => '2026-13-01'], 'issued_from'];
        yield 'a last day in another format' => [['issued_to' => '1. 11. 2026'], 'issued_to'];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('invalidParameters')]
    public function testAParameterThatCannotBeUsedIsNamed(array $query, string $parameter): void
    {
        $this->get('/api/v1/invoices', $query);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => $parameter, 'title' => 'Bad Request']);
    }

    public function testTheLimitsOfTheLimitAreAccepted(): void
    {
        $this->get('/api/v1/invoices', ['limit' => '1']);
        self::assertResponseIsSuccessful();
        $this->get('/api/v1/invoices', ['limit' => '100']);
        self::assertResponseIsSuccessful();
        $this->get('/api/v1/invoices', ['cursor' => '']);
        self::assertResponseIsSuccessful();
    }

    public function testTheOpenApiDocumentDescribesTheListOfInvoices(): void
    {
        $this->client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        $parameters = $this->at('paths', '/api/v1/invoices', 'get', 'parameters');
        self::assertIsArray($parameters);
        self::assertSame(['kind', 'customer_id', 'state', 'trashed', 'issued_from', 'issued_to', 'q', 'limit', 'cursor', 'view', 'filter'], array_column($parameters, 'name'));
        self::assertSame('#/components/schemas/InvoicePage', $this->at('paths', '/api/v1/invoices', 'get', 'responses', '200', 'content', 'application/json', 'schema', '$ref'));
        $this->at('components', 'schemas', 'InvoiceDetail');
    }
}
