<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract\Event;

use Foresi\Shared\Contract\Event\DomainEvent;
use Foresi\Shared\Contract\Event\DomainEventPayload;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * A project's name, client, description or custom fields were saved, from its
 * settings or the API; each save records one. The payload is thin (ADR 0030):
 * read the project itself for what it holds. It names the keys of the custom
 * fields that changed (ADR 0045), never their values; empty when none did.
 */
#[DomainEvent('work.project.updated', version: 1)]
final readonly class ProjectUpdated implements DomainEventPayload
{
    /**
     * @param list<string> $customFields the keys of the custom fields that changed
     */
    public function __construct(
        public string $projectId,
        public array $customFields = [],
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('project', $this->projectId);
    }
}
