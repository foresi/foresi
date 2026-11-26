<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

/**
 * One task as another module shows it: its title, where it stands, who has it
 * and when it is due, and a link to its page.
 */
final readonly class TaskSummary
{
    public function __construct(
        public string $id,
        public string $title,
        /** The name the project gives the status. */
        public string $statusName,
        /** What the status means, whatever its name: "open", "active", "done" or "closed". */
        public string $statusType,
        /** The membership of the person responsible, or null for nobody. */
        public ?string $responsibleId,
        public ?string $responsibleName,
        public ?\DateTimeImmutable $dueOn,
        /** In a finished status, done or closed. */
        public bool $done,
        /** In the trash, from where it can still be restored. */
        public bool $trashed,
        /** The address of the task's page, relative to the site. */
        public string $url,
    ) {
    }
}
