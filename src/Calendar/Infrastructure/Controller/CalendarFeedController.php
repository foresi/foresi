<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Infrastructure\Controller;

use Foresi\Calendar\Application\CalendarFeedReader;
use Foresi\Calendar\Domain\FeedToken;
use Foresi\Shared\Infrastructure\Security\PublicRoute;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The address a calendar app subscribes to: open to anyone with it, on a
 * firewall with no user and no session (Foresi\Shared\Infrastructure\Security\PublicRoute),
 * like the links to sent documents. A malformed, unknown or revoked token, and
 * the feed of someone who left the staff, get the same 404, so the answer tells
 * nothing about what exists. A token may be fetched 30 times an hour; calendar
 * apps fetch every few hours, so more is a misbehaving client, answered 429.
 */
#[AsController]
final readonly class CalendarFeedController
{
    public const string ROUTE = 'app_calendar_feed';

    /** Any token-like path part reaches the controller, which answers a malformed one like an unknown one. */
    private const string TOKEN = '[^/]{1,200}';

    private const array HEADERS = [
        'Content-Type' => 'text/calendar; charset=utf-8',
        'Content-Disposition' => 'inline; filename="calendar.ics"',
        'Cache-Control' => 'private, no-store',
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
        'X-Content-Type-Options' => 'nosniff',
        AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER => 'true',
    ];

    public function __construct(
        private CalendarFeedReader $feeds,
        #[Autowire(service: 'limiter.calendar_feed')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/calendar/feed/{token}.ics', name: self::ROUTE, requirements: ['token' => self::TOKEN], defaults: [PublicRoute::DEFAULT => true], methods: ['GET', 'HEAD'], stateless: true)]
    public function __invoke(#[\SensitiveParameter] string $token): Response
    {
        $limit = $this->limiter->create(FeedToken::hash($token))->consume();
        if (!$limit->isAccepted()) {
            return new Response('Too many requests for this calendar feed.', Response::HTTP_TOO_MANY_REQUESTS, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
            ]);
        }
        $feed = $this->feeds->open($token);
        if (!$feed instanceof \Foresi\Calendar\Domain\CalendarFeed) {
            return new Response('No such calendar feed.', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Robots-Tag' => 'noindex, nofollow']);
        }

        return new Response($this->feeds->calendar($feed), Response::HTTP_OK, self::HEADERS);
    }
}
