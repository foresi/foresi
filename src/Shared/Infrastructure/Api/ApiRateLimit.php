<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Api;

use Foresi\Shared\Application\Api\ApiClient;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Counts every request of the REST API and of the MCP server over HTTP against
 * one budget of its token (the api_token limiter, config/packages/rate_limiter.yaml),
 * so a client cannot spend twice by switching surface. A REST write (POST, PATCH
 * or DELETE) spends the write budget too (WriteBudget, ADR 0032). Every answer to
 * a signed-in request says the budget, what is left of it and the seconds until
 * the next request is let through, zero while some is left; a write says the
 * budget of the two with less left. Over a budget the answer is 429 rate_limited
 * with Retry-After. It runs after the firewall, which signs the token in, and
 * before the controller.
 */
final readonly class ApiRateLimit
{
    public const string RATE_LIMITED = 'rate_limited';
    /** The path of the MCP server and what lies below it, as its firewall matches it. */
    public const string MCP_PATH = '#^/mcp(/|$)#';
    private const string ATTRIBUTE = '_api_rate_limit';

    private const array WRITES = ['POST', 'PATCH', 'DELETE'];

    public function __construct(private RateLimiterFactoryInterface $apiTokenLimiter, private ApiClient $client, private WriteBudget $writes)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->counts($request->getPathInfo())) {
            return;
        }
        $tokenId = $this->client->tokenId();
        if (null === $tokenId) {
            return;
        }
        $limit = $this->apiTokenLimiter->create($tokenId)->consume();
        $message = 'This token made too many requests. Try again later.';
        if ($limit->isAccepted() && \in_array($request->getMethod(), self::WRITES, true) && str_starts_with($request->getPathInfo(), ApiErrors::PATH_PREFIX)) {
            $write = $this->writes->spend();
            if ($write instanceof RateLimit && (!$write->isAccepted() || $write->getRemainingTokens() < $limit->getRemainingTokens())) {
                $limit = $write;
                $message = 'This token made too many writes. Try again later.';
            }
        }
        $request->attributes->set(self::ATTRIBUTE, $limit);
        if (!$limit->isAccepted()) {
            $event->setResponse(ProblemResponse::create(
                Response::HTTP_TOO_MANY_REQUESTS,
                self::RATE_LIMITED,
                $message,
                ['Retry-After' => (string) max(1, $this->secondsUntil($limit))],
            ));
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $limit = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if (!$limit instanceof RateLimit) {
            return;
        }
        $headers = $event->getResponse()->headers;
        $headers->set('RateLimit-Limit', (string) $limit->getLimit());
        $headers->set('RateLimit-Remaining', (string) $limit->getRemainingTokens());
        $headers->set('RateLimit-Reset', (string) $this->secondsUntil($limit));
    }

    private function counts(string $path): bool
    {
        return str_starts_with($path, ApiErrors::PATH_PREFIX) || 1 === preg_match(self::MCP_PATH, $path);
    }

    private function secondsUntil(RateLimit $limit): int
    {
        return max(0, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
