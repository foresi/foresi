<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Api;

use App\Events\Application\Query\EventRow;
use App\Events\Infrastructure\Catalogue\EventCatalogue;
use App\Events\Infrastructure\Catalogue\UnknownEventType;
use App\Events\Infrastructure\Payload\EventPayloads;
use App\Shared\Domain\Event\EventSubject;
use App\Shared\Infrastructure\Api\JsonValues;

/**
 * An event as REST and MCP publish it. The tenant, the user and the token stay in
 * the log. The facts pass through the event class, so a row written before a fact
 * was added shows the fact too, with its default.
 */
final readonly class EventView
{
    public function __construct(
        private EventCatalogue $catalogue,
        private EventPayloads $payloads,
        private SubjectUrls $urls,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function of(EventRow $row): array
    {
        return [
            'id' => $row->id,
            'type' => $row->name,
            'version' => $row->version,
            'position' => $row->position,
            'occurred_at' => JsonValues::timestamp($row->occurredAt),
            'subject' => [
                'type' => $row->subjectType,
                'id' => $row->subjectId,
                'url' => $this->urls->url(explode('.', $row->name, 2)[0], new EventSubject($row->subjectType, $row->subjectId)),
            ],
            'data' => $this->facts($row),
            'correlation_id' => $row->correlationId,
            'actor' => ['kind' => $row->actorKind],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(EventRow $row): array
    {
        try {
            $type = $this->catalogue->byName($row->name, $row->version);
        } catch (UnknownEventType) {
            // A version this release does not know, such as after a downgrade: the facts as stored.
            return $row->data;
        }

        return $this->payloads->normalise($this->payloads->denormalise($type, $row->data));
    }
}
