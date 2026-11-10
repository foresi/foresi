<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Api;

/**
 * What every endpoint of the REST API shares: problem documents for unknown
 * routes and wrong methods, the rate limit of a token and its headers, and the
 * OpenAPI document.
 */
final class ApiPlumbingTest extends ApiTestCase
{
    public function testAnUnknownRouteIsNotFound(): void
    {
        $this->get('/api/v1/nothing');
        $this->assertProblem(404, 'not_found', ['title' => 'Not Found']);

        $this->client->request('GET', '/api/v2/invoices');
        $this->assertProblem(404, 'not_found');
    }

    public function testAWrongMethodIsNotAllowed(): void
    {
        $this->client->request('POST', '/api/v1/invoices', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->secret]);

        $this->assertProblem(405, 'method_not_allowed', ['title' => 'Method Not Allowed']);
        self::assertResponseHeaderSame('Allow', 'GET');
    }

    public function testEveryAnswerToATokenSaysItsBudget(): void
    {
        $this->get('/api/v1/me');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('RateLimit-Limit', '600');
        self::assertResponseHeaderSame('RateLimit-Remaining', '599');
        self::assertResponseHeaderSame('RateLimit-Reset', '0');

        $this->get('/api/v1/invoices/nothing');

        $this->assertProblem(404, 'not_found');
        self::assertResponseHeaderSame('RateLimit-Remaining', '598');
    }

    public function testEveryTokenHasABudgetOfItsOwn(): void
    {
        $this->get('/api/v1/me');
        $this->get('/api/v1/me', secret: $this->issued());

        self::assertResponseHeaderSame('RateLimit-Remaining', '599');
    }

    public function testAnAnswerWithoutATokenHasNoBudget(): void
    {
        $this->client->request('GET', '/api/v1/me');

        $this->assertProblem(401, 'missing_token');
        self::assertResponseNotHasHeader('RateLimit-Limit');
    }

    public function testOverTheBudgetTheAnswerIsTooManyRequests(): void
    {
        $this->get('/api/v1/me');
        $tokenId = $this->at('token', 'id');
        self::assertIsString($tokenId);
        $limiter = self::getContainer()->get('test.api_token_limiter');
        self::assertTrue($limiter->create($tokenId)->consume(599)->isAccepted());

        $this->get('/api/v1/me');

        $this->assertProblem(429, 'rate_limited', ['title' => 'Too Many Requests']);
        self::assertResponseHeaderSame('RateLimit-Limit', '600');
        self::assertResponseHeaderSame('RateLimit-Remaining', '0');
        $retryAfter = (int) $this->client->getResponse()->headers->get('Retry-After');
        self::assertGreaterThanOrEqual(1, $retryAfter);
        self::assertLessThanOrEqual(601, $retryAfter);
        self::assertSame((string) $retryAfter, $this->client->getResponse()->headers->get('Retry-After'));
        $reset = (int) $this->client->getResponse()->headers->get('RateLimit-Reset');
        self::assertGreaterThanOrEqual(1, $reset);
        self::assertLessThanOrEqual(601, $reset);
    }

    public function testTheOpenApiDocumentDescribesEveryEndpointAndTheBearerToken(): void
    {
        $this->client->request('GET', '/api/v1/openapi.json');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSame('Foresi API', $this->at('info', 'title'));
        self::assertSame('1', $this->at('info', 'version'));
        $paths = $this->at('paths');
        self::assertIsArray($paths);
        self::assertSame([
            '/api/v1/me',
            '/api/v1/invoices',
            '/api/v1/invoices/{id}',
            '/api/v1/payments',
            '/api/v1/payments/{id}',
            '/api/v1/contacts',
            '/api/v1/contacts/{id}',
        ], array_keys($paths));
        self::assertSame([['bearer' => []]], $this->at('security'));
        self::assertSame('http', $this->at('components', 'securitySchemes', 'bearer', 'type'));
        self::assertSame('bearer', $this->at('components', 'securitySchemes', 'bearer', 'scheme'));
        foreach (array_keys($paths) as $path) {
            $item = $this->at('paths', $path);
            self::assertIsArray($item);
            self::assertSame(['get'], array_keys($item), $path);
            $this->at('paths', $path, 'get', 'responses', '200');
            $this->at('paths', $path, 'get', 'responses', '401');
        }
        $parameters = $this->at('paths', '/api/v1/invoices', 'get', 'parameters');
        self::assertIsArray($parameters);
        self::assertSame(['kind', 'firm_id', 'customer_id', 'state', 'trashed', 'issued_from', 'issued_to', 'q', 'limit', 'cursor'], array_column($parameters, 'name'));
        self::assertSame('#/components/schemas/InvoicePage', $this->at('paths', '/api/v1/invoices', 'get', 'responses', '200', 'content', 'application/json', 'schema', '$ref'));
        $this->at('components', 'schemas', 'InvoiceDetail');
    }
}
