<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Application;

use App\Attachment\Domain\Attachment;

/**
 * The attachments of the active tenant. Deleted ones are not found.
 */
interface Attachments
{
    /**
     * The attachments of a record, oldest first.
     *
     * @return list<Attachment>
     */
    public function of(string $ownerKind, string $ownerId): array;

    public function get(string $id): ?Attachment;

    public function add(Attachment $attachment): void;

    public function flush(): void;
}
