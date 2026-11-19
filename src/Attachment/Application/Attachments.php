<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Attachment\Application;

use Foresi\Attachment\Domain\Attachment;

/**
 * The attachments of the instance. Deleted ones are not found, except to be
 * restored or purged: by deleted(), deletedOf() and deletedBefore().
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
     * A deleted attachment, for a restore; one that stands is not found.
     */
    public function deleted(string $id): ?Attachment;

    /**
     * The deleted attachments of a record, the most recently deleted first.
     *
     * @return list<Attachment>
     */
    public function deletedOf(string $ownerKind, string $ownerId): array;

    /**
     * The attachments deleted before the moment, of every record.
     *
     * @return list<Attachment>
     */
    public function deletedBefore(\DateTimeImmutable $moment): array;

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
