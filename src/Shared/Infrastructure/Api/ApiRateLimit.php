<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Api;

use App\Shared\Application\Api\ApiClient;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Counts every request of the REST API against the budget of its token (the
 * api_token limiter, config/packages/rate_limiter.yaml). Every answer to a signed-in
 * request says the budget, what is left of it and the seconds until the next request
 * is let through, zero while some is left; over the budget the answer is 429
 * rate_limited with Retry-After. It runs after the firewall, which signs the token
 * in, and before the controller.
 */
final readonly class ApiRateLimit
{
    public const string RATE_LIMITED = 'rate_limited';
    private const string ATTRIBUTE = '_api_rate_limit';

    public function __construct(private RateLimiterFactoryInterface $apiTokenLimiter, private ApiClient $client)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), ApiErrors::PATH_PREFIX)) {
            return;
        }
        $tokenId = $this->client->tokenId();
        if (null === $tokenId) {
            return;
        }
        $limit = $this->apiTokenLimiter->create($tokenId)->consume();
        $request->attributes->set(self::ATTRIBUTE, $limit);
        if (!$limit->isAccepted()) {
            $event->setResponse(ProblemResponse::create(
                Response::HTTP_TOO_MANY_REQUESTS,
                self::RATE_LIMITED,
                'This token made too many requests. Try again later.',
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

    private function secondsUntil(RateLimit $limit): int
    {
        return max(0, $limit->getRetryAfter()->getTimestamp() - time());
    }
}
