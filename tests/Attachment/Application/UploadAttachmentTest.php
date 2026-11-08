<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Attachment\Application;

use App\Attachment\Application\AttachmentAccess;
use App\Attachment\Application\AttachmentFiles;
use App\Attachment\Application\AttachmentPermissions;
use App\Attachment\Application\AttachmentRefused;
use App\Attachment\Application\Attachments;
use App\Attachment\Application\AttachmentUpload;
use App\Attachment\Application\DeleteAttachment;
use App\Attachment\Application\UploadAttachment;
use App\Attachment\Domain\Attachment;
use App\Audit\Application\AuditActor;
use App\Audit\Application\AuditCorrelation;
use App\Shared\Domain\Id;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Application\TenantScope;
use App\Tenancy\Domain\TenantId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class UploadAttachmentTest extends TestCase
{
    /** The smallest PNG: one transparent pixel. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $owner;
    private string $user;
    /** @var list<string> */
    private array $paths = [];
    private InMemoryAttachments $attachments;
    private RecordingFiles $files;
    private FakeAccess $access;
    private ?string $actor;

    protected function setUp(): void
    {
        $this->owner = Id::generate();
        $this->user = Id::generate();
        $this->actor = $this->user;
        $this->attachments = new InMemoryAttachments();
        $this->files = new RecordingFiles();
        $this->access = new FakeAccess();
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    public function testAFileIsKeptWithTheTypeOfItsBytesNeverTheOneTheBrowserClaims(): void
    {
        $path = $this->file((string) base64_decode(self::PNG, true));

        $attachment = $this->upload()->upload('task', $this->owner, new AttachmentUpload($path, 'C:\\fakepath\\pixel.exe', 999));

        self::assertSame('image/png', $attachment->mediaType());
        self::assertSame('pixel.exe', $attachment->fileName());
        self::assertSame(filesize($path), $attachment->size(), 'The size is measured, not the reported one.');
        self::assertSame(hash_file('sha256', $path), $attachment->sha256());
        self::assertSame($this->user, $attachment->uploadedBy());
        self::assertSame([$attachment], $this->attachments->items);
        self::assertSame([$attachment->id()], array_keys($this->files->stored));
    }

    public function testHtmlStaysWhatItIsAndAnUnknownTypeIsBytes(): void
    {
        $html = $this->upload()->upload('task', $this->owner, new AttachmentUpload($this->file('<!doctype html><html><script>alert(1)</script></html>'), 'page.png', 10));
        $bytes = $this->upload()->upload('task', $this->owner, new AttachmentUpload($this->file("\x00\x01\x02\x03\xff"), 'blob', 5));

        self::assertSame('text/html', $html->mediaType());
        self::assertSame('application/octet-stream', $bytes->mediaType());
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->assertRefused(AttachmentRefused::EMPTY, new AttachmentUpload($this->file(''), 'empty.txt', 0));
    }

    public function testAFileLargerThanTheLimitIsRefused(): void
    {
        $this->assertRefused(AttachmentRefused::TOO_BIG, new AttachmentUpload($this->file('eleven bytes'), 'big.txt', 11), 10);
        // A size the web server already refused, or reported larger than allowed.
        $this->assertRefused(AttachmentRefused::TOO_BIG, new AttachmentUpload('', 'big.txt', 0, false, true));
        $this->assertRefused(AttachmentRefused::TOO_BIG, new AttachmentUpload($this->file('x'), 'big.txt', UploadAttachment::MAX_BYTES + 1));
    }

    public function testAFileThatDidNotArriveOrCannotBeStoredIsRefused(): void
    {
        $this->assertRefused(AttachmentRefused::FAILED, new AttachmentUpload($this->file('x'), 'partial.txt', 1, false));
        $this->assertRefused(AttachmentRefused::FAILED, new AttachmentUpload('/nonexistent/file', 'gone.txt', 1));

        $this->files->fail = true;
        $this->assertRefused(AttachmentRefused::FAILED, new AttachmentUpload($this->file('x'), 'a.txt', 1));
    }

    public function testTheFileIsRemovedWhenItsRecordCannotBeSaved(): void
    {
        $this->attachments->fail = true;
        try {
            $this->upload()->upload('task', $this->owner, new AttachmentUpload($this->file('x'), 'a.txt', 1));
            self::fail('Saved.');
        } catch (\RuntimeException) {
            self::assertSame([], $this->files->stored);
        }
    }

    public function testARecordThePersonCannotSeeIsUnknownAndOneTheyCannotAttachToIsNotAllowed(): void
    {
        $this->assertRefused(AttachmentRefused::UNKNOWN, new AttachmentUpload($this->file('x'), 'a.txt', 1), kind: 'invoice');
        $this->assertRefused(AttachmentRefused::UNKNOWN, new AttachmentUpload($this->file('x'), 'a.txt', 1), owner: 'not-an-id');
        $this->access->see = false;
        $this->assertRefused(AttachmentRefused::UNKNOWN, new AttachmentUpload($this->file('x'), 'a.txt', 1));
        $this->access->see = true;
        $this->access->attach = false;
        $this->assertRefused(AttachmentRefused::NOT_ALLOWED, new AttachmentUpload($this->file('x'), 'a.txt', 1));
        $this->access->attach = true;
        $this->actor = null;
        $this->assertRefused(AttachmentRefused::NOT_ALLOWED, new AttachmentUpload($this->file('x'), 'a.txt', 1));
        self::assertSame([], $this->files->stored);
    }

    public function testTheUploaderOrWhomTheModuleLetsDeletes(): void
    {
        $attachment = $this->upload()->upload('task', $this->owner, new AttachmentUpload($this->file('x'), 'a.txt', 1));

        $this->actor = Id::generate();
        try {
            $this->delete()->delete($attachment->id());
            self::fail('Deleted by someone else.');
        } catch (AttachmentRefused $refused) {
            self::assertSame(AttachmentRefused::NOT_ALLOWED, $refused->reason);
        }
        $this->access->delete = true;
        $this->delete()->delete($attachment->id());
        self::assertTrue($attachment->isDeleted());
        self::assertSame([$attachment->id()], array_keys($this->files->stored), 'The file stays until a purge.');

        $this->access->delete = false;
        $this->actor = $this->user;
        $own = $this->upload()->upload('task', $this->owner, new AttachmentUpload($this->file('y'), 'b.txt', 1));
        $this->delete()->delete($own->id());
        self::assertTrue($own->isDeleted());

        $this->expectExceptionObject(new AttachmentRefused(AttachmentRefused::UNKNOWN));
        $this->delete()->delete($own->id());
    }

    public function testTwoModulesCannotAnswerForOneKind(): void
    {
        $this->expectException(\LogicException::class);

        new AttachmentPermissions([new FakeAccess(), new FakeAccess()])->canSee('task', $this->owner);
    }

    private function assertRefused(string $reason, AttachmentUpload $file, int $limit = UploadAttachment::MAX_BYTES, string $kind = 'task', ?string $owner = null): void
    {
        try {
            $this->upload($limit)->upload($kind, $owner ?? $this->owner, $file);
            self::fail('Not refused: '.$reason);
        } catch (AttachmentRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    private function upload(int $limit = UploadAttachment::MAX_BYTES): UploadAttachment
    {
        return new UploadAttachment($this->attachments, $this->files, new AttachmentPermissions([$this->access]), $this->tenant(), $this->actor(), new AuditCorrelation(), new MockClock(), $limit);
    }

    private function delete(): DeleteAttachment
    {
        return new DeleteAttachment($this->attachments, new AttachmentPermissions([$this->access]), $this->actor(), new AuditCorrelation(), new MockClock());
    }

    private function actor(): AuditActor
    {
        return new readonly class($this->actor) implements AuditActor {
            public function __construct(private ?string $id)
            {
            }

            public function userId(): ?string
            {
                return $this->id;
            }
        };
    }

    private function tenant(): TenantContext
    {
        $context = new TenantContext(new class implements TenantScope {
            public function activate(TenantId $tenant): void
            {
            }

            public function deactivate(): void
            {
            }
        });
        $context->set(TenantId::generate());

        return $context;
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, $bytes);
        $this->paths[] = $path;

        return $path;
    }
}

final class FakeAccess implements AttachmentAccess
{
    public bool $see = true;
    public bool $attach = true;
    public bool $delete = false;

    public function kinds(): array
    {
        return ['task'];
    }

    public function canSee(string $ownerKind, string $ownerId): bool
    {
        return $this->see;
    }

    public function canAttach(string $ownerKind, string $ownerId): bool
    {
        return $this->attach;
    }

    public function canDelete(Attachment $attachment): bool
    {
        return $this->delete;
    }
}

final class InMemoryAttachments implements Attachments
{
    /** @var list<Attachment> */
    public array $items = [];
    public bool $fail = false;

    public function of(string $ownerKind, string $ownerId): array
    {
        return array_values(array_filter($this->items, static fn (Attachment $a): bool => !$a->isDeleted() && $a->belongsTo($ownerKind, $ownerId)));
    }

    public function get(string $id): ?Attachment
    {
        return array_find($this->items, static fn (Attachment $a): bool => !$a->isDeleted() && $a->id() === $id);
    }

    public function add(Attachment $attachment): void
    {
        $this->items[] = $attachment;
    }

    public function flush(): void
    {
        if ($this->fail) {
            $this->items = [];

            throw new \RuntimeException('The database is gone.');
        }
    }
}

final class RecordingFiles implements AttachmentFiles
{
    /** @var array<string, string> */
    public array $stored = [];
    public bool $fail = false;

    public function store(string $tenantId, string $attachmentId, string $sourcePath): string
    {
        if ($this->fail) {
            throw new \RuntimeException('The disk is full.');
        }
        $this->stored[$attachmentId] = $tenantId.'/'.$attachmentId;

        return $tenantId.'/'.$attachmentId;
    }

    public function read(string $tenantId, string $key, string $sha256): string
    {
        return $key;
    }

    public function locate(string $tenantId, string $key): string
    {
        return $key;
    }

    public function remove(string $tenantId, string $key): void
    {
        $this->stored = array_filter($this->stored, static fn (string $stored): bool => $stored !== $key);
    }
}
