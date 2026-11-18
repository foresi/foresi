<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Security;

use Foresi\Shared\Infrastructure\Security\PublicRoute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * A route is public when its module marks it with the default _public; the
 * stateless matcher also wants the route option stateless.
 */
final class PublicRouteTest extends TestCase
{
    /**
     * @param array<string, mixed> $attributes
     */
    #[DataProvider('routes')]
    public function testMatchesTheMarkedRoutes(array $attributes, bool $public, bool $publicAndStateless): void
    {
        $request = new Request(attributes: $attributes);

        self::assertSame($public, new PublicRoute()->matches($request));
        self::assertSame($publicAndStateless, new PublicRoute(stateless: true)->matches($request));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool, bool}>
     */
    public static function routes(): iterable
    {
        yield 'unmarked' => [['_route' => 'app_dashboard'], false, false];
        yield 'public' => [['_public' => true], true, false];
        yield 'public and stateless' => [['_public' => true, '_stateless' => true], true, true];
        yield 'stateless only' => [['_stateless' => true], false, false];
        yield 'not exactly true' => [['_public' => '1', '_stateless' => true], false, false];
    }
}
