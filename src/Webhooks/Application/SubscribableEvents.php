<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * The events an endpoint can subscribe to, as the event catalogue and the
 * modules' access services tell: which names a pattern stands for, and which
 * modules the signed-in person may read.
 */
interface SubscribableEvents
{
    /**
     * @return list<string> the event names of the catalogue a name or "module.*" stands for; none when it stands for nothing
     */
    public function names(string $pattern): array;

    /**
     * @return list<string> the ids of the modules whose events the signed-in person may read, sorted
     */
    public function readableModules(): array;
}
