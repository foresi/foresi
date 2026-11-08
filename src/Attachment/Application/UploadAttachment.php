<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Attachment\Application;

use App\Attachment\Domain\Attachment;
use App\Audit\Application\AuditActor;
use App\Audit\Application\AuditCorrelation;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use Psr\Clock\ClockInterface;

/**
 * Keeps a file for a record the person may attach to. The type is sniffed
 * from the bytes, never taken from the browser; the file is stored first and
 * removed again when the record of it cannot be saved.
 */
final readonly class UploadAttachment
{
    public const int MAX_BYTES = 50 * 1024 * 1024;
    private const string FALLBACK_TYPE = 'application/octet-stream';

    public function __construct(
        private Attachments $attachments,
        private AttachmentFiles $files,
        private AttachmentPermissions $permissions,
        private TenantContext $context,
        private AuditActor $actor,
        private AuditCorrelation $correlation,
        private ClockInterface $clock,
        private int $maxBytes = self::MAX_BYTES,
    ) {
    }

    /**
     * @throws AttachmentRefused
     */
    public function upload(string $ownerKind, string $ownerId, AttachmentUpload $file): Attachment
    {
        if (!Id::isValid($ownerId) || !$this->permissions->canSee($ownerKind, $ownerId)) {
            throw new AttachmentRefused(AttachmentRefused::UNKNOWN);
        }
        $userId = $this->actor->userId();
        if (null === $userId || !$this->permissions->canAttach($ownerKind, $ownerId)) {
            throw new AttachmentRefused(AttachmentRefused::NOT_ALLOWED);
        }
        if ($file->tooBig || $file->size > $this->maxBytes) {
            throw new AttachmentRefused(AttachmentRefused::TOO_BIG);
        }
        if (!$file->received || !is_file($file->path) || !is_readable($file->path)) {
            throw new AttachmentRefused(AttachmentRefused::FAILED);
        }
        clearstatcache(true, $file->path);
        $size = filesize($file->path);
        if (false === $size) {
            throw new AttachmentRefused(AttachmentRefused::FAILED);
        }
        if (0 === $size) {
            throw new AttachmentRefused(AttachmentRefused::EMPTY);
        }
        if ($size > $this->maxBytes) {
            throw new AttachmentRefused(AttachmentRefused::TOO_BIG);
        }
        $sha256 = hash_file('sha256', $file->path);
        if (false === $sha256) {
            throw new AttachmentRefused(AttachmentRefused::FAILED);
        }
        $mediaType = self::mediaType(new \finfo(\FILEINFO_MIME_TYPE)->file($file->path));

        $id = Id::generate();
        $tenantId = $this->context->current()->toString();
        try {
            $key = $this->files->store($tenantId, $id, $file->path);
        } catch (\RuntimeException $failed) {
            throw new AttachmentRefused(AttachmentRefused::FAILED, $failed);
        }
        try {
            $attachment = new Attachment($id, $ownerKind, $ownerId, $file->clientName, $mediaType, $size, $sha256, $key, $userId, $this->clock->now());

            return $this->correlation->within($this->correlation->current() ?? 'attachment:'.$id, function () use ($attachment): Attachment {
                $this->attachments->add($attachment);
                $this->attachments->flush();

                return $attachment;
            });
        } catch (\Throwable $failed) {
            $this->files->remove($tenantId, $key);

            throw $failed instanceof \DomainException ? new AttachmentRefused(AttachmentRefused::FAILED, $failed) : $failed;
        }
    }

    /**
     * The media type the bytes were sniffed as, when it is a well-formed one.
     */
    public static function mediaType(string|false $sniffed): string
    {
        if (!\is_string($sniffed) || \strlen($sniffed) > Attachment::MAX_MEDIA_TYPE_LENGTH || 1 !== preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~iD', $sniffed)) {
            return self::FALLBACK_TYPE;
        }

        return strtolower($sniffed);
    }
}
