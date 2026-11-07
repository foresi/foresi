<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The health of the installation for an uptime monitor: no user, no session,
 * 200 while ok or degraded and 503 when down. Each check has its status and a
 * value: seconds since the last beat for the worker and the schedule, the
 * count of failed messages, the free percent of the data disk. Limited per
 * client address, generously for a monitor.
 */
#[AsController]
final readonly class HealthController
{
    public const string ROUTE = 'app_health';

    public function __construct(
        private HealthCheck $check,
        #[Autowire(service: 'limiter.health')]
        private RateLimiterFactoryInterface $limiter,
        #[Autowire(param: 'app.version')]
        private string $version,
    ) {
    }

    #[Route('/health', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return new Response('', Response::HTTP_TOO_MANY_REQUESTS);
        }
        $health = $this->check->run();
        $status = $health->status();

        return new JsonResponse(
            ['status' => $status, 'version' => $this->version, 'checks' => $health->checks],
            HealthStatus::Down === $status ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }
}
