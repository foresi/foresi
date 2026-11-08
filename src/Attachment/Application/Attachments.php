<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Application;

use App\Attachment\Domain\Attachment;

/**
 * The attachments of the active tenant. Deleted ones are not found, except by
 * the purge, which removes them for good.
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

    /**
     * Every attachment of these records, deleted ones included.
     *
     * @param list<string> $ownerIds
     *
     * @return list<Attachment>
     */
    public function allOf(string $ownerKind, array $ownerIds): array;

    /**
     * Removes the record of the attachment for good; its file is the caller's.
     */
    public function remove(Attachment $attachment): void;

    public function add(Attachment $attachment): void;

    public function flush(): void;
}
