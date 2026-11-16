<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application\Query;

use App\Shared\Domain\Id;
use App\Webhooks\Application\UnknownWebhookDelivery;
use App\Webhooks\Application\UnknownWebhookEndpoint;
use App\Webhooks\Application\WebhookNotAllowed;

/**
 * What was sent to an endpoint, for those who may manage it: its deliveries of
 * the last thirty days, newest first, and each with its attempts.
 */
final readonly class WebhookDeliveryLog
{
    public const int DEFAULT_LIMIT = 25;
    public const int MAX_LIMIT = 100;

    public function __construct(private WebhookEndpointList $endpoints, private DeliveryRecords $records)
    {
    }

    /**
     * @param string|null $afterId the id of the last delivery of the slice before; null for the first slice
     *
     * @throws UnknownWebhookEndpoint
     * @throws WebhookNotAllowed
     */
    public function slice(string $endpointId, ?string $afterId = null, int $limit = self::DEFAULT_LIMIT): DeliverySlice
    {
        $this->endpoints->show($endpointId);
        if (null !== $afterId && !Id::isValid($afterId)) {
            return new DeliverySlice([], false);
        }
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $rows = $this->records->before($endpointId, $afterId, $limit + 1);

        return new DeliverySlice(\array_slice($rows, 0, $limit), \count($rows) > $limit);
    }

    /**
     * @throws UnknownWebhookEndpoint
     * @throws UnknownWebhookDelivery
     * @throws WebhookNotAllowed
     */
    public function delivery(string $endpointId, string $deliveryId): DeliveryDetail
    {
        $this->endpoints->show($endpointId);
        $row = Id::isValid($deliveryId) ? $this->records->find($endpointId, $deliveryId) : null;
        if (!$row instanceof DeliveryRow) {
            throw new UnknownWebhookDelivery();
        }

        return new DeliveryDetail($row, $this->records->attempts($row->id));
    }
}
