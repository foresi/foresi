<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Api;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of PATCH /api/v1/webhook-endpoints/{id}: only the fields that are
 * sent change, so a field left out is null. A description sent as null clears
 * it, which only the raw body tells from one left out (see description()).
 */
final readonly class UpdateEndpointRequest
{
    /**
     * @param list<string>|null $eventTypes
     */
    public function __construct(
        public ?string $url = null,
        public ?string $description = null,
        #[Assert\Count(min: 1, max: 100)]
        #[Assert\All([new Assert\Type('string')])]
        #[SerializedName('event_types')]
        public ?array $eventTypes = null,
        public ?int $version = null,
        public ?bool $active = null,
    ) {
    }

    /**
     * The description as the use case takes it: null keeps it, blank clears it.
     *
     * @param array<mixed> $payload the decoded body as it was sent
     */
    public function description(array $payload): ?string
    {
        return $this->description ?? (\array_key_exists('description', $payload) ? '' : null);
    }
}
