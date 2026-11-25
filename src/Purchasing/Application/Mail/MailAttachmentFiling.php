<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Mail;

use Foresi\Attachment\Application\AttachmentUpload;
use Foresi\Identity\Application\StaffCheck;
use Foresi\Mail\Contract\MailAttachment;
use Foresi\Mail\Contract\Messages;
use Foresi\Purchasing\Application\PurchasingUnit;
use Foresi\Purchasing\Application\ReceivedFileTypes;
use Foresi\Purchasing\Application\UploadReceivedDocuments;
use Foresi\Purchasing\Domain\DocumentSource;
use Foresi\Purchasing\Domain\MailIntake;
use Psr\Clock\ClockInterface;

/**
 * The background job that turns the attachments of one stored mail into
 * received-document drafts, for the person whose address the mail came to.
 * Nobody is signed in: the person is the uploader, as long as they still work
 * for the organisation.
 *
 * An attachment counts when its bytes are of an accepted type (ReceivedFileTypes)
 * and it is within the size limit. An inline image with a Content-ID is a
 * signature or a logo and is left out; an inline PDF is kept, as Apple Mail
 * sends attached PDFs inline. A mail makes at most MAX_DRAFTS drafts, so one
 * with hundreds of attachments cannot flood the drafts. What is left out is
 * listed on the intake row with its reason. A file already on another document
 * is no reason to refuse: the draft warns about it.
 *
 * Each draft is stored on its own, and the drafts made so far are counted from
 * the drafts themselves, so a run that starts again after a crash goes on
 * where the one before stopped and no attachment becomes two drafts. The intake
 * row is locked when the outcome is written, so a second run finds it written.
 */
final readonly class MailAttachmentFiling
{
    public const int MAX_DRAFTS = 50;

    /** An image shown inside the mail: a signature or a logo. */
    public const string INLINE_IMAGE = 'inline_image';
    /** Not a type a reader opens. */
    public const string UNSUPPORTED_TYPE = 'unsupported_type';
    public const string TOO_LARGE = 'too_large';
    /** Beyond the drafts one mail may make. */
    public const string TOO_MANY = 'too_many';
    /** The person the address belongs to no longer works for the organisation. */
    public const string OWNER_LEFT = 'owner_left';

    public function __construct(
        private MailIntakes $intakes,
        private Messages $messages,
        private StaffCheck $staff,
        private UploadReceivedDocuments $uploads,
        private PurchasingUnit $unit,
        private ClockInterface $clock,
    ) {
    }

    public function file(string $messageId): void
    {
        $intake = $this->intakes->find($messageId);
        if (!$intake instanceof MailIntake || !$intake->isQueued()) {
            return;
        }
        $owner = $intake->ownerUserId();
        if (!$this->staff->isStaffMember($owner)) {
            $this->finish($messageId, 0, [['name' => '', 'reason' => self::OWNER_LEFT]]);

            return;
        }
        [$counted, $skipped] = $this->sort($messageId);
        foreach (\array_slice($counted, $this->intakes->draftsOf($messageId)) as $part) {
            $this->draft($owner, $messageId, $part);
        }
        $this->finish($messageId, \count($counted), $skipped);
    }

    /**
     * When the queue gives up on the job, the mail says it failed.
     */
    public function giveUp(string $messageId): void
    {
        $this->unit->run(function () use ($messageId): void {
            $this->intakes->lock($messageId)?->fail($this->now());
        });
    }

    /**
     * @return array{list<MailAttachment>, list<array{name: string, reason: string}>} the attachments that count in mail order, and those left out
     */
    private function sort(string $messageId): array
    {
        $counted = [];
        $skipped = [];
        foreach ($this->messages->attachments($messageId) as $part) {
            $reason = $this->leftOut($messageId, $part);
            if (null === $reason && \count($counted) >= self::MAX_DRAFTS) {
                $reason = self::TOO_MANY;
            }
            if (null === $reason) {
                $counted[] = $part;
            } else {
                $skipped[] = ['name' => $part->name, 'reason' => $reason];
            }
        }

        return [$counted, $skipped];
    }

    private function leftOut(string $messageId, MailAttachment $part): ?string
    {
        if ($part->inline && null !== $part->contentId && str_starts_with(strtolower($part->contentType), 'image/')) {
            return self::INLINE_IMAGE;
        }
        if ($part->size > ReceivedFileTypes::MAX_BYTES) {
            return self::TOO_LARGE;
        }
        $bytes = $this->messages->attachment($messageId, $part->index)->bytes;
        if (\strlen($bytes) > ReceivedFileTypes::MAX_BYTES) {
            return self::TOO_LARGE;
        }

        return new ReceivedFileTypes()->accepts($bytes, $part->name) ? null : self::UNSUPPORTED_TYPE;
    }

    private function draft(string $owner, string $messageId, MailAttachment $part): void
    {
        $bytes = $this->messages->attachment($messageId, $part->index)->bytes;
        $path = tempnam(sys_get_temp_dir(), 'foresi-mail-');
        if (false === $path || false === file_put_contents($path, $bytes)) {
            throw new \RuntimeException('A mail attachment could not be written to a temporary file.');
        }
        try {
            $name = trim($part->name);
            $made = $this->uploads->uploadFor($owner, [new AttachmentUpload($path, '' === $name ? 'attachment' : $name, \strlen($bytes))], DocumentSource::Mail, $messageId)[0];
        } finally {
            @unlink($path);
        }
        if (!$made->isDrafted()) {
            // The attachment store failed; the queue tries again, from this attachment on.
            throw new \RuntimeException(\sprintf('A mail attachment was not stored (%s).', $made->refusal ?? 'unknown'));
        }
    }

    /**
     * @param list<array{name: string, reason: string}> $skipped
     */
    private function finish(string $messageId, int $drafts, array $skipped): void
    {
        $this->unit->run(function () use ($messageId, $drafts, $skipped): void {
            $this->intakes->lock($messageId)?->finish($drafts, $skipped, $this->now());
        });
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
