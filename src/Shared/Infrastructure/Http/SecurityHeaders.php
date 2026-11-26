<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The security headers of every response (OWASP Secure Headers Project; OWASP ASVS 5.0 3.4).
 * A controller that sets one of them itself keeps its own: the public document links, the
 * attachments and the calendar feeds are stricter than the pages.
 *
 * - X-Content-Type-Options: nosniff, so a downloaded CSV or a user's file is never guessed
 *   into a page or a script (ASVS 3.4.4).
 * - Content-Security-Policy (ContentSecurityPolicy), and X-Frame-Options: SAMEORIGIN for
 *   browsers that do not read frame-ancestors.
 * - Referrer-Policy: same-origin. Other sites get no referrer and no address of a record
 *   (ASVS 3.4.5); requests to the application keep their Origin, which the CSRF check of
 *   Symfony reads (Fetch, "Origin header").
 * - Permissions-Policy: no camera, microphone, location, payment or other device APIs, which
 *   no screen uses.
 * - Cross-Origin-Opener-Policy: same-origin, so a page opened from another site cannot reach
 *   the application's window (ASVS 3.4.8).
 * - Strict-Transport-Security over HTTPS only (RFC 6797 7.2), one year with the subdomains by
 *   default (ASVS 3.4.1); HSTS_MAX_AGE=0 turns it off.
 *
 * It runs before the web profiler's toolbar listener, which adds its own nonces to this
 * policy in development.
 */
final readonly class SecurityHeaders
{
    private const string PERMISSIONS = 'accelerometer=(), autoplay=(), camera=(), display-capture=(), geolocation=(), gyroscope=(), hid=(), magnetometer=(), microphone=(), midi=(), payment=(), publickey-credentials-get=(), screen-wake-lock=(), serial=(), usb=(), xr-spatial-tracking=()';

    public function __construct(
        private ContentSecurityPolicy $policy,
        private CspNonce $nonce,
        #[Autowire(param: 'foresi.hsts.max_age')]
        private int $hstsMaxAge,
        #[Autowire(param: 'foresi.hsts.include_subdomains')]
        private bool $hstsSubdomains,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -64)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        $headers = $response->headers;
        $set = static function (string $name, string $value) use ($headers): void {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        };

        $set('X-Content-Type-Options', 'nosniff');
        // Symfony's debug exception page inlines its scripts and styles; production errors are ours.
        if (!$headers->has('X-Debug-Exception')) {
            $set('Content-Security-Policy', $this->policy->header($this->nonce->used()));
        }
        $set('X-Frame-Options', 'SAMEORIGIN');
        $set('Referrer-Policy', 'same-origin');
        $set('Permissions-Policy', self::PERMISSIONS);
        $set('Cross-Origin-Opener-Policy', 'same-origin');
        if ($this->hstsMaxAge > 0 && $request->isSecure()) {
            $set('Strict-Transport-Security', 'max-age='.$this->hstsMaxAge.($this->hstsSubdomains ? '; includeSubDomains' : ''));
        }
    }
}
