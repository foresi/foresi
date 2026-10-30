<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Registry;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Remembers public registry answers for 24 hours. Registry data is public, not
 * tenant data, so keys hold only the registry and the normalised identifier.
 */
final readonly class RegistryCache
{
    private const int TTL_SECONDS = 86400;

    public function __construct(private CacheItemPoolInterface $registryCache)
    {
    }

    /**
     * Returns the cached answer, or asks $fetch and caches what it returns.
     * $fetch returns null when the register was unreachable: that is not cached,
     * so the next call tries again. An empty array means "known: nothing there".
     *
     * @param callable(): (array<mixed>|null) $fetch
     *
     * @return array<mixed>|null
     */
    public function remember(string $registry, string $identifier, callable $fetch): ?array
    {
        $cached = $this->get($registry, $identifier);
        if (null !== $cached) {
            return $cached;
        }

        $fresh = $fetch();
        if (null !== $fresh) {
            $this->put($registry, $identifier, $fresh);
        }

        return $fresh;
    }

    /**
     * @return array<mixed>|null
     */
    public function get(string $registry, string $identifier): ?array
    {
        $item = $this->registryCache->getItem($this->key($registry, $identifier));
        $value = $item->isHit() ? $item->get() : null;

        return \is_array($value) ? $value : null;
    }

    /**
     * @param array<mixed> $value
     */
    public function put(string $registry, string $identifier, array $value): void
    {
        $item = $this->registryCache->getItem($this->key($registry, $identifier));
        $this->registryCache->save($item->set($value)->expiresAfter(self::TTL_SECONDS));
    }

    private function key(string $registry, string $identifier): string
    {
        return $registry.'.'.sha1($identifier);
    }
}
