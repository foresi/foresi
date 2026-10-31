<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Controller;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The pages of every import target that has some, by target key. */
final readonly class TargetPagesRegistry
{
    public const string TAG = 'app.import_target_pages';

    /** @var array<string, TargetPages> */
    private array $pages;

    /**
     * @param iterable<TargetPages> $pages
     */
    public function __construct(
        #[AutowireIterator(self::TAG)]
        iterable $pages,
    ) {
        $byKey = [];
        foreach ($pages as $page) {
            $byKey[$page->targetKey()] = $page;
        }
        $this->pages = $byKey;
    }

    /** Null for a target without pages: its import screens then link nowhere. */
    public function find(string $targetKey): ?TargetPages
    {
        return $this->pages[$targetKey] ?? null;
    }
}
