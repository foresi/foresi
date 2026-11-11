<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\ApiTokenGrant;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;

/**
 * Puts the grant of a bearer token on the security token, where the token
 * context, the active tenant and the audit actor read it.
 */
final readonly class ApiTokenGrantOnToken
{
    #[AsEventListener(event: AuthenticationTokenCreatedEvent::class, dispatcher: 'security.event_dispatcher.'.ApiFirewalls::API)]
    #[AsEventListener(event: AuthenticationTokenCreatedEvent::class, dispatcher: 'security.event_dispatcher.'.ApiFirewalls::MCP)]
    public function __invoke(AuthenticationTokenCreatedEvent $event): void
    {
        $badge = $event->getPassport()->getBadge(UserBadge::class);
        $grant = $badge instanceof UserBadge ? ($badge->getAttributes()[SecurityApiTokenContext::ATTRIBUTE] ?? null) : null;
        if ($grant instanceof ApiTokenGrant) {
            $event->getAuthenticatedToken()->setAttribute(SecurityApiTokenContext::ATTRIBUTE, $grant);
        }
    }
}
