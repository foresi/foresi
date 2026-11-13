<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Api;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of POST /api/v1/webhook-endpoints. What the fields may hold beyond
 * their form (an https URL that resolves outside private networks, event types
 * of the catalogue the person may read) is the use case's to decide.
 */
final readonly class CreateEndpointRequest
{
    /**
     * @param list<string> $eventTypes
     */
    public function __construct(
        #[Assert\NotBlank]
        public ?string $url = null,
        #[Assert\NotNull]
        #[Assert\Count(min: 1, max: 100)]
        #[Assert\All([new Assert\Type('string')])]
        #[SerializedName('event_types')]
        public ?array $eventTypes = null,
        public ?string $description = null,
        public int $version = 1,
    ) {
    }
}
