<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Api;

use Foresi\Shared\Application\Api\ApiClient;
use Foresi\Shared\Infrastructure\Api\ApiRateLimit;
use Foresi\Shared\Infrastructure\Api\WriteBudget;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ApiRateLimitTest extends TestCase
{
    private InMemoryStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorage();
    }

    public function testARequestOfATokenSpendsOneOfItsBudget(): void
    {
        $limit = $this->limit('token-1');

        [$request, $event] = $this->request('/api/v1/invoices');
        $limit->onRequest($event);
        self::assertNull($event->getResponse());
        $response = $this->respond($limit, $request);

        self::assertSame('3', $response->headers->get('RateLimit-Limit'));
        self::assertSame('2', $response->headers->get('RateLimit-Remaining'));
        self::assertSame('0', $response->headers->get('RateLimit-Reset'));
        self::assertFalse($response->headers->has('Retry-After'));
    }

    public function testOverTheBudgetTheRequestIsRefusedAndSaysWhenToTryAgain(): void
    {
        $limit = $this->limit('token-1');
        for ($i = 0; $i < 3; ++$i) {
            $limit->onRequest($this->request('/api/v1/me')[1]);
        }

        [$request, $event] = $this->request('/api/v1/me');
        $limit->onRequest($event);
        $refusal = $event->getResponse();

        self::assertInstanceOf(Response::class, $refusal);
        self::assertSame(429, $refusal->getStatusCode());
        self::assertSame('application/problem+json', $refusal->headers->get('Content-Type'));
        $body = json_decode((string) $refusal->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('rate_limited', $body['code']);
        self::assertSame('This token made too many requests. Try again later.', $body['detail']);
        $retryAfter = (int) $refusal->headers->get('Retry-After');
        self::assertGreaterThanOrEqual(1, $retryAfter);
        self::assertLessThanOrEqual(61, $retryAfter);
        $response = $this->respond($limit, $request, $refusal);
        self::assertSame('0', $response->headers->get('RateLimit-Remaining'));
        self::assertSame((string) $retryAfter, $response->headers->get('RateLimit-Reset'));
    }

    public function testEveryTokenHasItsOwnBudget(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $this->limit('token-1')->onRequest($this->request('/api/v1/me')[1]);
        }
        [$request, $event] = $this->request('/api/v1/me');
        $other = $this->limit('token-2');

        $other->onRequest($event);

        self::assertNull($event->getResponse());
        self::assertSame('2', $this->respond($other, $request)->headers->get('RateLimit-Remaining'));
    }

    public function testOtherPathsSubRequestsAndRequestsWithoutATokenAreNotCounted(): void
    {
        foreach ([['/invoices', 'token-1', HttpKernelInterface::MAIN_REQUEST], ['/mcpx', 'token-1', HttpKernelInterface::MAIN_REQUEST], ['/x/mcp', 'token-1', HttpKernelInterface::MAIN_REQUEST], ['/api', 'token-1', HttpKernelInterface::MAIN_REQUEST], ['/mcp', 'token-1', HttpKernelInterface::SUB_REQUEST], ['/api/v1/me', 'token-1', HttpKernelInterface::SUB_REQUEST], ['/api/v1/me', null, HttpKernelInterface::MAIN_REQUEST]] as [$path, $token, $type]) {
            $limit = $this->limit($token);
            [$request, $event] = $this->request($path, $type);

            $limit->onRequest($event);

            self::assertNull($event->getResponse(), $path);
            self::assertFalse($this->respond($limit, $request)->headers->has('RateLimit-Limit'), $path);
        }
        [$request, $event] = $this->request('/api/v1/me');
        $this->limit('token-1')->onRequest($event);
        self::assertSame('2', $this->respond($this->limit('token-1'), $request)->headers->get('RateLimit-Remaining'), 'Nothing above was counted.');
    }

    public function testTheMcpServerSpendsTheSameBudget(): void
    {
        $limit = $this->limit('token-1');
        $limit->onRequest($this->request('/api/v1/me')[1]);
        $limit->onRequest($this->request('/mcp/')[1]);

        [$request, $event] = $this->request('/mcp');
        $limit->onRequest($event);
        self::assertNull($event->getResponse());
        self::assertSame('0', $this->respond($limit, $request)->headers->get('RateLimit-Remaining'));

        [, $event] = $this->request('/mcp');
        $limit->onRequest($event);
        $refusal = $event->getResponse();
        self::assertInstanceOf(Response::class, $refusal);
        self::assertSame(429, $refusal->getStatusCode());
        self::assertTrue($refusal->headers->has('Retry-After'));
    }

    private function limit(?string $tokenId): ApiRateLimit
    {
        $client = new readonly class($tokenId) implements ApiClient {
            public function __construct(private ?string $tokenId)
            {
            }

            public function tokenId(): ?string
            {
                return $this->tokenId;
            }
        };
        $factory = new RateLimiterFactory(['id' => 'api_token', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 minute'], $this->storage);
        $writes = new RateLimiterFactory(['id' => 'api_token_write', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '1 minute'], $this->storage);

        return new ApiRateLimit($factory, $client, new WriteBudget($writes, $client));
    }

    public function testAWriteSpendsTheWriteBudgetTooAndSaysTheOneWithLessLeft(): void
    {
        $limit = $this->limit('token-1');

        [$request, $event] = $this->request('/api/v1/contacts', method: 'POST');
        $limit->onRequest($event);
        self::assertNull($event->getResponse());
        $response = $this->respond($limit, $request);
        self::assertSame('2', $response->headers->get('RateLimit-Limit'), 'The write budget has less left.');
        self::assertSame('1', $response->headers->get('RateLimit-Remaining'));

        $limit->onRequest($this->request('/api/v1/contacts', method: 'PATCH')[1]);
        [, $event] = $this->request('/api/v1/contacts', method: 'DELETE');
        $limit->onRequest($event);
        $refusal = $event->getResponse();
        self::assertInstanceOf(Response::class, $refusal);
        self::assertSame(429, $refusal->getStatusCode());
        self::assertStringContainsString('too many writes', (string) $refusal->getContent());
    }

    public function testAReadAndAnMcpRequestSpendNoWrite(): void
    {
        $limit = $this->limit('token-1');
        $limit->onRequest($this->request('/mcp', method: 'POST')[1]);

        [$request, $event] = $this->request('/api/v1/contacts');
        $limit->onRequest($event);

        self::assertSame('3', $this->respond($limit, $request)->headers->get('RateLimit-Limit'));
    }

    /**
     * @return array{Request, RequestEvent}
     */
    private function request(string $path, int $type = HttpKernelInterface::MAIN_REQUEST, string $method = 'GET'): array
    {
        $request = Request::create($path, $method);

        return [$request, new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $type)];
    }

    private function respond(ApiRateLimit $limit, Request $request, ?Response $response = null): Response
    {
        $event = new ResponseEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response ?? new Response());
        $limit->onResponse($event);

        return $event->getResponse();
    }
}
