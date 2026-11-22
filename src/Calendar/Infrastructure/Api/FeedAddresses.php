<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Infrastructure\Api;

use Foresi\Calendar\Infrastructure\Controller\CalendarFeedController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The address of a feed, on the host the person is using, and the same with the
 * webcal scheme, which opens the calendar app to subscribe.
 */
final readonly class FeedAddresses
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function address(#[\SensitiveParameter] string $token): string
    {
        return $this->urls->generate(CalendarFeedController::ROUTE, ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function webcal(#[\SensitiveParameter] string $token): string
    {
        return (string) preg_replace('#^https?://#', 'webcal://', $this->address($token));
    }
}
