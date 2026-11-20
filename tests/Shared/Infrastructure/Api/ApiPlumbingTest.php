<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Api;

use Foresi\Shared\Infrastructure\Module\InstalledModules;

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
        $this->client->request('POST', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->secret]);

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

        $this->get('/api/v1/contacts/nothing');

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
        // Each path with its operations and the status each answers when it succeeds, by the module that serves it.
        $byModule = [
            'cash-flow' => [
                '/api/v1/cash-flow/forecast' => ['get' => '200'],
                '/api/v1/cash-flow/planned-items' => ['get' => '200', 'post' => '201'],
                '/api/v1/cash-flow/planned-items/{plannedItemId}' => ['get' => '200', 'delete' => '200', 'patch' => '200'],
                '/api/v1/cash-flow/checkpoints' => ['post' => '201'],
                '/api/v1/cash-flow/expected-dates/{subjectType}/{subjectId}' => ['delete' => '200'],
                '/api/v1/cash-flow/expected-dates' => ['post' => '200'],
                '/api/v1/cash-flow/minimum-balance' => ['post' => '200'],
                '/api/v1/cash-flow/planned-items/{plannedItemId}/end' => ['post' => '200'],
                '/api/v1/cash-flow/planned-items/{plannedItemId}/skip' => ['post' => '200'],
            ],
            'events' => [
                '/api/v1/events' => ['get' => '200'],
            ],
            'identity' => [
                '/api/v1/me' => ['get' => '200'],
            ],
            'invoicing' => [
                '/api/v1/invoices' => ['get' => '200', 'post' => '201'],
                '/api/v1/invoices/{invoiceId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/invoices/{invoiceId}/trash' => ['post' => '200'],
                '/api/v1/invoices/{invoiceId}/restore' => ['post' => '200'],
                '/api/v1/invoices/{invoiceId}/mark-sent' => ['post' => '200'],
                '/api/v1/payments' => ['get' => '200'],
                '/api/v1/payments/{id}' => ['get' => '200'],
            ],
            'party' => [
                '/api/v1/firm' => ['get' => '200'],
                '/api/v1/contacts' => ['get' => '200', 'post' => '201'],
                '/api/v1/contacts/{contactId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/contacts/{contactId}/archive' => ['post' => '200'],
                '/api/v1/contacts/{contactId}/contact-persons' => ['post' => '200'],
                '/api/v1/contacts/{contactId}/restore' => ['post' => '200'],
                '/api/v1/contacts/{contactId}/contact-persons/{personId}' => ['delete' => '200'],
            ],
            'purchasing' => [
                '/api/v1/received-documents' => ['get' => '200', 'post' => '201'],
                '/api/v1/received-documents/{documentId}' => ['get' => '200'],
                '/api/v1/received-documents/{documentId}/payments' => ['get' => '200', 'post' => '201'],
                '/api/v1/received-documents/{documentId}/mark-paid' => ['post' => '200'],
                '/api/v1/received-documents/{documentId}/payments/{paymentId}' => ['delete' => '200'],
                '/api/v1/received-documents/{documentId}/trash' => ['post' => '200'],
            ],
            'taxes-and-contributions' => [
                '/api/v1/taxes-and-contributions/profile' => ['get' => '200'],
                '/api/v1/taxes-and-contributions/profile/statuses' => ['post' => '201'],
                '/api/v1/taxes-and-contributions/profile/statuses/{statusId}' => ['delete' => '200'],
                '/api/v1/taxes-and-contributions/profile/years/{year}' => ['put' => '200'],
                '/api/v1/taxes-and-contributions/profile/authorities/{authority}' => ['put' => '200'],
            ],
            'shared' => [
                '/api/v1/lists' => ['get' => '200'],
                '/api/v1/views' => ['get' => '200', 'post' => '201'],
                '/api/v1/views/{viewId}' => ['get' => '200', 'delete' => '200', 'patch' => '200'],
                '/api/v1/views/{viewId}/count' => ['get' => '200'],
                '/api/v1/views/restore-defaults' => ['post' => '200'],
            ],
            'work' => [
                '/api/v1/projects' => ['get' => '200', 'post' => '201'],
                '/api/v1/projects/{projectId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/projects/{projectId}/people' => ['get' => '200'],
                '/api/v1/projects/{projectId}/archive' => ['post' => '200'],
                '/api/v1/projects/{projectId}/restore' => ['post' => '200'],
                '/api/v1/tasks' => ['get' => '200', 'post' => '201'],
                '/api/v1/tasks/{taskId}' => ['get' => '200', 'patch' => '200'],
                '/api/v1/tasks/{taskId}/comments' => ['get' => '200', 'post' => '201'],
                '/api/v1/tasks/{taskId}/trash' => ['post' => '200'],
                '/api/v1/tasks/{taskId}/restore' => ['post' => '200'],
                '/api/v1/task-comments/{commentId}' => ['patch' => '200'],
                '/api/v1/task-comments/{commentId}/trash' => ['post' => '200'],
                '/api/v1/labels' => ['get' => '200'],
                '/api/v1/time-entries' => ['get' => '200', 'post' => '201'],
                '/api/v1/time-entries/timer' => ['post' => '201'],
                '/api/v1/time-entries/{entryId}/stop' => ['post' => '200'],
            ],
            'webhooks' => [
                '/api/v1/webhook-endpoints/{id}/deliveries' => ['get' => '200'],
                '/api/v1/webhook-endpoints/{id}/deliveries/{deliveryId}' => ['get' => '200'],
                '/api/v1/webhook-endpoints/{id}/deliveries/{deliveryId}/redeliver' => ['post' => '202'],
                '/api/v1/webhook-endpoints' => ['get' => '200', 'post' => '201'],
                '/api/v1/webhook-endpoints/{id}' => ['get' => '200', 'delete' => '204', 'patch' => '200'],
                '/api/v1/webhook-endpoints/{id}/secret/rotate' => ['post' => '200'],
                '/api/v1/webhook-endpoints/{id}/secret' => ['get' => '200'],
                '/api/v1/webhook-endpoints/{id}/ping' => ['post' => '202'],
            ],
        ];
        $operations = [];
        foreach (new InstalledModules(\dirname(__DIR__, 4))->graph()->all() as $module) {
            $operations += $byModule[$module->id->value] ?? [];
        }
        // The document follows the order of the routes, which follows the order the modules load in.
        $expected = array_keys($operations);
        $actual = array_keys($paths);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertSame([['bearer' => []]], $this->at('security'));
        self::assertSame('http', $this->at('components', 'securitySchemes', 'bearer', 'type'));
        self::assertSame('bearer', $this->at('components', 'securitySchemes', 'bearer', 'scheme'));
        foreach ($operations as $path => $methods) {
            $item = $this->at('paths', $path);
            self::assertIsArray($item);
            self::assertSame(array_keys($methods), array_keys($item), $path);
            foreach ($methods as $method => $success) {
                $this->at('paths', $path, $method, 'responses', $success);
                $this->at('paths', $path, $method, 'responses', '401');
            }
        }
    }
}
