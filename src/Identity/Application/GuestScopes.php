<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Application;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Shared\Application\Api\ApiResources;

/**
 * The scopes a guest's OAuth connection may hold (ADR 0041): those the
 * modules open to guests, such as "tasks:write" and "projects:read". A guest
 * is offered only these at consent, and a connection is narrowed to them
 * whenever its person is a guest, so someone who turns from member into guest
 * keeps only what a guest may do. "read" and "write" mean every read and every
 * write a guest may hold.
 */
final readonly class GuestScopes
{
    public function __construct(private ApiResources $resources)
    {
    }

    /**
     * @return list<ApiScope> each open resource's read, then its write where a guest may write
     */
    public function offered(): array
    {
        $offered = [];
        foreach ($this->resources->guest() as $value) {
            $scope = ApiScope::tryFrom($value);
            $resource = $scope?->resource();
            if (null === $resource) {
                continue;
            }
            $offered[] = ApiScope::of($resource, false);
            if ($scope->writes()) {
                $offered[] = $scope;
            }
        }

        return $offered;
    }

    /**
     * What of these scopes a guest may hold, in the fewest scopes.
     *
     * @param list<ApiScope> $scopes
     *
     * @return list<ApiScope> empty when none of them is open to guests
     */
    public function narrow(array $scopes): array
    {
        return ApiScope::smallest(array_filter(
            $this->offered(),
            static fn (ApiScope $open): bool => array_any($scopes, static fn (ApiScope $held): bool => $held->grants($open)),
        ));
    }
}
