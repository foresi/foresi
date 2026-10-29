<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;

/**
 * The one sanctioned way to read across tenants. The tenant filter is only
 * suspended while the callback runs and always restored afterwards. Suspending
 * keeps the filter instance and its tenant parameter, so the entity manager is
 * not cleared: managed entities and pending changes survive. Without an active
 * tenant the restored filter still has no parameter and fails closed.
 *
 * The callback must return scalar data only (ids, strings, numbers, nested in
 * arrays). Entities loaded here would stay in the identity map without the
 * tenant filter ever applying to them, so objects are refused.
 */
final readonly class TenantFilterBypass
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $filters = $this->entityManager->getFilters();
        // Not enabled (already suspended, nested call, or switched off on purpose): nothing to change or restore.
        if (!$filters->isEnabled(TenantFilter::NAME)) {
            return self::scalarOnly($callback());
        }

        $filters->suspend(TenantFilter::NAME);
        try {
            return self::scalarOnly($callback());
        } finally {
            $filters->restore(TenantFilter::NAME);
        }
    }

    private static function scalarOnly(mixed $result): mixed
    {
        if (\is_object($result)) {
            throw new \LogicException('A cross-tenant lookup must return scalar data, not objects.');
        }
        if (\is_array($result)) {
            array_walk_recursive($result, static function (mixed $item): void {
                self::scalarOnly($item);
            });
        }

        return $result;
    }
}
