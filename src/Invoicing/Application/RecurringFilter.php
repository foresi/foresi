<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Domain\RecurringState;
use Foresi\Shared\Application\View\ViewFilter;

/**
 * Which recurring templates a list shows: of one firm or all, in one state or any,
 * only those whose last run failed, those of a view, and which page. The views in
 * $counted are counted beside it, in the one query that counts the list (spec D9).
 */
final readonly class RecurringFilter
{
    /** By the next issue, the templates without one last; the list's own order. */
    public const string NEXT = 'next';
    public const string NAME = 'name';
    public const array SORTS = [self::NEXT, self::NAME];

    /**
     * @param array<string, ViewFilter> $counted by view key
     */
    public function __construct(
        public ?string $firmId,
        public ?RecurringState $state,
        public bool $failing,
        public int $page,
        public ?ViewFilter $view = null,
        public array $counted = [],
        public string $sort = self::NEXT,
    ) {
    }
}
