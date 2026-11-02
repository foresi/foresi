<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure;

use App\Banking\Application\PollFailures;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The failure counts of the poller in one cache item, as the schedule keeps its
 * own state. Only the poller writes it, under the lock of its task. The counts
 * are kept a week after the last failure and at most for MAX messages, the
 * newest failures winning, so a mailbox that keeps changing never grows it.
 */
final readonly class CachePollFailures implements PollFailures
{
    private const string KEY = 'banking.poll_failures';
    private const int TTL = 7 * 24 * 3600;
    private const int MAX = 1000;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function failed(string $uid): int
    {
        $counts = $this->counts();
        $count = ($counts[$uid] ?? 0) + 1;
        unset($counts[$uid]);
        $counts[$uid] = $count;
        $this->save(\array_slice($counts, -self::MAX, null, true));

        return $count;
    }

    public function clear(string $uid): void
    {
        $counts = $this->counts();
        if (isset($counts[$uid])) {
            unset($counts[$uid]);
            $this->save($counts);
        }
    }

    public function atLeast(int $failures): array
    {
        return array_map(strval(...), array_keys(array_filter($this->counts(), static fn (int $count): bool => $count >= $failures)));
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $value = $this->cache->getItem(self::KEY)->get();
        if (!\is_array($value)) {
            return [];
        }
        $counts = [];
        foreach ($value as $uid => $count) {
            if (\is_int($count)) {
                $counts[(string) $uid] = $count;
            }
        }

        return $counts;
    }

    /**
     * @param array<string, int> $counts
     */
    private function save(array $counts): void
    {
        $this->cache->save($this->cache->getItem(self::KEY)->set($counts)->expiresAfter(self::TTL));
    }
}
