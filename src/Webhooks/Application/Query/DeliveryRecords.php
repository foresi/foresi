<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application\Query;

/**
 * The stored deliveries and attempts of the active organisation.
 */
interface DeliveryRecords
{
    /**
     * The deliveries of an endpoint, newest first, those before the given id.
     *
     * @return list<DeliveryRow>
     */
    public function before(string $endpointId, ?string $beforeId, int $limit): array;

    public function find(string $endpointId, string $deliveryId): ?DeliveryRow;

    /**
     * @return list<AttemptRow> oldest first
     */
    public function attempts(string $deliveryId): array;
}
