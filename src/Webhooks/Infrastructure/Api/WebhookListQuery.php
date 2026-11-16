<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Api;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of the lists of endpoints and of deliveries: paging only.
 */
final readonly class WebhookListQuery
{
    public function __construct(
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,
        public ?string $cursor = null,
    ) {
    }
}
