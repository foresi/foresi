<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * The routes anyone may open, marked by the module that owns them with the
 * route default "_public" set to true, so config/packages/security.yaml
 * never lists the paths of a module. The router runs before the firewall,
 * so the route's defaults are among the request's attributes here.
 *
 * The stateless variant matches the public routes that are also stateless
 * (the route option "stateless"), such as the health check: they get a
 * firewall with no user and no session.
 */
final readonly class PublicRoute implements RequestMatcherInterface
{
    public const string DEFAULT = '_public';

    public function __construct(
        private bool $stateless = false,
    ) {
    }

    public function matches(Request $request): bool
    {
        return true === $request->attributes->get(self::DEFAULT)
            && (!$this->stateless || true === $request->attributes->get('_stateless'));
    }
}
