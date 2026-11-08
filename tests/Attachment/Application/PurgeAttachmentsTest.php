<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Attachment\Application;

use App\Attachment\Application\AttachmentFiles;
use App\Attachment\Application\Attachments;
use App\Attachment\Application\PurgeAttachments;
use App\Attachment\Domain\Attachment;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Application\TenantScope;
use App\Tenancy\Domain\TenantId;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * The records go first, with the module's transaction; the files after, and
 * one that cannot be removed is logged and left behind.
 */
final class PurgeAttachmentsTest extends TestCase
{
    public function testTheRecordsGoFirstAndTheFilesOnlyWhenAskedAfter(): void
    {
        [$purge, $attachments, $files] = $this->purge();
        $owner = Id::generate();
        $kept = $this->attachment('task', Id::generate());
        $attachments->items = [$this->attachment('task', $owner), $this->attachment('task', $owner, deleted: true), $kept];

        $keys = $purge->forget('task', [$owner]);

        self::assertCount(2, $keys);
        self::assertSame([$kept], $attachments->items);
        self::assertSame([], $files->removed);

        $purge->removeFiles($keys);
        self::assertSame($keys, $files->removed);
    }

    public function testAFileThatCannotBeRemovedIsLoggedAndTheOthersStillGo(): void
    {
        [$purge, , $files, $logger] = $this->purge();
        $files->failing = ['stuck'];

        $purge->removeFiles(['stuck', 'gone']);

        self::assertSame(['gone'], $files->removed);
        self::assertSame([['warning', 'stuck']], $logger->records);
    }

    /**
     * @return array{PurgeAttachments, PurgedAttachments, PurgedFiles, WarningLogger}
     */
    private function purge(): array
    {
        $context = new TenantContext(self::createStub(TenantScope::class));
        $context->switchTo(TenantId::generate());
        $attachments = new PurgedAttachments();
        $files = new PurgedFiles();
        $logger = new WarningLogger();

        return [new PurgeAttachments($attachments, $files, $context, $logger), $attachments, $files, $logger];
    }

    private function attachment(string $kind, string $ownerId, bool $deleted = false): Attachment
    {
        $id = Id::generate();
        $attachment = new Attachment($id, $kind, $ownerId, 'notes.txt', 'text/plain', 5, str_repeat('a', 64), 'tenant/'.$id, Id::generate(), new \DateTimeImmutable());
        if ($deleted) {
            $attachment->delete(new \DateTimeImmutable());
        }

        return $attachment;
    }
}

final class PurgedAttachments implements Attachments
{
    /** @var list<Attachment> */
    public array $items = [];

    public function of(string $ownerKind, string $ownerId): array
    {
        return [];
    }

    public function get(string $id): ?Attachment
    {
        return null;
    }

    public function allOf(string $ownerKind, array $ownerIds): array
    {
        return array_values(array_filter($this->items, static fn (Attachment $a): bool => $a->ownerKind() === $ownerKind && \in_array($a->ownerId(), $ownerIds, true)));
    }

    public function remove(Attachment $attachment): void
    {
        $this->items = array_values(array_filter($this->items, static fn (Attachment $a): bool => $a !== $attachment));
    }

    public function add(Attachment $attachment): void
    {
        $this->items[] = $attachment;
    }

    public function flush(): void
    {
        throw new \LogicException('The purge leaves the flush to the module of the records.');
    }
}

final class PurgedFiles implements AttachmentFiles
{
    /** @var list<string> */
    public array $removed = [];
    /** @var list<string> */
    public array $failing = [];

    public function store(string $tenantId, string $attachmentId, string $sourcePath): string
    {
        throw new \LogicException('Not stored here.');
    }

    public function read(string $tenantId, string $key, string $sha256): string
    {
        throw new \LogicException('Not read here.');
    }

    public function locate(string $tenantId, string $key): string
    {
        throw new \LogicException('Not read here.');
    }

    public function remove(string $tenantId, string $key): void
    {
        if (\in_array($key, $this->failing, true)) {
            throw new \RuntimeException('Permission denied.');
        }
        $this->removed[] = $key;
    }
}

final class WarningLogger extends AbstractLogger
{
    /** @var list<array{string, string}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $key = $context['key'] ?? '';
        $this->records[] = [\is_string($level) ? $level : '', \is_string($key) ? $key : ''];
    }
}
