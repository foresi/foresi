<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Application;

use Foresi\Mail\Contract\InboundPurpose;

/**
 * The purposes the installed modules registered, by key.
 */
final class InboundPurposes
{
    /** @var array<string, InboundPurpose>|null */
    private ?array $byKey = null;

    /**
     * @param iterable<InboundPurpose> $purposes
     */
    public function __construct(private readonly iterable $purposes)
    {
    }

    public function get(string $key): ?InboundPurpose
    {
        if (null === $this->byKey) {
            $this->byKey = [];
            foreach ($this->purposes as $purpose) {
                $this->byKey[$purpose->key()] ??= $purpose;
            }
        }

        return $this->byKey[$key] ?? null;
    }
}
