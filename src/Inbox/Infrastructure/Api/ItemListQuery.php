<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Api;

use Foresi\Inbox\Domain\ItemState;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /api/v1/inbox/items. Every parameter narrows the list.
 */
final readonly class ItemListQuery
{
    public function __construct(
        #[Assert\Choice(choices: ['new', 'done', 'discarded'])]
        public ?string $state = null,
        public bool $unmatched = false,
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,
        public ?string $cursor = null,
        /** Only the items with or without an open suggestion. */
        #[Assert\Choice(choices: ['with', 'without'])]
        public ?string $suggestions = null,
    ) {
    }

    /**
     * @return bool|null true for the items with an open suggestion, false for those without, null for both
     */
    public function withOpenSuggestions(): ?bool
    {
        return null === $this->suggestions ? null : 'with' === $this->suggestions;
    }

    public function state(): ?ItemState
    {
        return null === $this->state ? null : ItemState::from($this->state);
    }
}
