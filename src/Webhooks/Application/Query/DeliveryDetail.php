<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application\Query;

/**
 * A delivery with its attempts, oldest first.
 */
final readonly class DeliveryDetail
{
    /**
     * @param list<AttemptRow> $attempts
     */
    public function __construct(public DeliveryRow $delivery, public array $attempts)
    {
    }
}
