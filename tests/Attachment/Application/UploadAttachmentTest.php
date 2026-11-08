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
use PHPUnit\Framework\Attributes\DataProvider;
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
    private AuditCorrelation $correlation;

    protected function setUp(): void
    {
        $this->owner = Id::generate();
        $this->user = Id::generate();
        $this->actor = $this->user;
        $this->correlation = new AuditCorrelation();
        $this->attachments = new InMemoryAttachments($this->correlation);
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

    public function testAFileOfExactlyTheLimitIsKeptAndOneByteMoreIsRefused(): void
    {
        $atLimit = $this->upload(10)->upload('task', $this->owner, new AttachmentUpload($this->file('0123456789'), 'ten.txt', 10));
        self::assertSame(10, $atLimit->size());

        $this->assertRefused(AttachmentRefused::TOO_BIG, new AttachmentUpload($this->file('0123456789'), 'lie.txt', 11), 10);
        // The size the browser reports is not trusted: the bytes are measured.
        $this->assertRefused(AttachmentRefused::TOO_BIG, new AttachmentUpload($this->file('0123456789A'), 'lie.txt', 1), 10);
    }

    public function testTheLimitIsFiftyMegabytesExactly(): void
    {
        $path = $this->sparse(UploadAttachment::MAX_BYTES);
        $kept = $this->upload()->upload('task', $this->owner, new AttachmentUpload($path, 'limit.bin', UploadAttachment::MAX_BYTES));
        self::assertSame(UploadAttachment::MAX_BYTES, $kept->size());

        $this->assertRefused(AttachmentRefused::TOO_BIG, new AttachmentUpload($this->sparse(UploadAttachment::MAX_BYTES + 1), 'over.bin', 1));
        $this->assertRefused(AttachmentRefused::TOO_BIG, new AttachmentUpload($this->file('x'), 'over.bin', UploadAttachment::MAX_BYTES + 1));
    }

    public function testADirectoryOrAnUnreadableFileIsNoUpload(): void
    {
        $this->assertRefused(AttachmentRefused::FAILED, new AttachmentUpload(sys_get_temp_dir(), 'folder', 1));

        $path = $this->file('secret');
        chmod($path, 0o000);
        if (!is_readable($path)) {
            $this->assertRefused(AttachmentRefused::FAILED, new AttachmentUpload($path, 'secret.txt', 6));
        }
        chmod($path, 0o600);
    }

    public function testTheSizeIsMeasuredAfresh(): void
    {
        $path = $this->file('');
        // The size of the empty file is remembered by PHP until the cache is cleared.
        self::assertSame(0, filesize($path));
        file_put_contents($path, 'now it has bytes');

        self::assertSame(16, $this->upload()->upload('task', $this->owner, new AttachmentUpload($path, 'late.txt', 16))->size());
    }

    public function testTheRecordIsWrittenUnderTheAuditCorrelationOfTheFileUnlessOneIsRunning(): void
    {
        $attachment = $this->upload()->upload('task', $this->owner, new AttachmentUpload($this->file('x'), 'a.txt', 1));
        $this->delete()->delete($attachment->id());
        self::assertSame(['attachment:'.$attachment->id(), 'attachment:'.$attachment->id()], $this->attachments->flushedUnder);
        self::assertNull($this->correlation->current());

        $this->attachments->flushedUnder = [];
        $this->correlation->within('import:1', function (): void {
            $other = $this->upload()->upload('task', $this->owner, new AttachmentUpload($this->file('y'), 'b.txt', 1));
            $this->delete()->delete($other->id());
        });
        self::assertSame(['import:1', 'import:1'], $this->attachments->flushedUnder);
    }

    /**
     * @return iterable<string, array{string|false, string}>
     */
    public static function sniffed(): iterable
    {
        yield 'nothing sniffed' => [false, 'application/octet-stream'];
        yield 'a plain type' => ['image/png', 'image/png'];
        yield 'capitals are lowered' => ['Image/PNG', 'image/png'];
        yield 'a structured suffix' => ['application/vnd.api+json', 'application/vnd.api+json'];
        yield 'no subtype' => ['image', 'application/octet-stream'];
        yield 'a trailing newline' => ["image/png\n", 'application/octet-stream'];
        yield 'a space inside' => ['image/p ng', 'application/octet-stream'];
        yield 'the longest type' => ['a/'.str_repeat('b', Attachment::MAX_MEDIA_TYPE_LENGTH - 2), 'a/'.str_repeat('b', Attachment::MAX_MEDIA_TYPE_LENGTH - 2)];
        yield 'one character too long' => ['a/'.str_repeat('b', Attachment::MAX_MEDIA_TYPE_LENGTH - 1), 'application/octet-stream'];
    }

    #[DataProvider('sniffed')]
    public function testASniffedTypeIsKeptOnlyWhenWellFormed(string|false $sniffed, string $expected): void
    {
        self::assertSame($expected, UploadAttachment::mediaType($sniffed));
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

    public function testAFileCanBeAttachedOnlyWhereTheModuleLetsThePersonSeeAndAttach(): void
    {
        $access = new FakeAccess();
        $permissions = new AttachmentPermissions([$access]);
        $owner = Id::generate();

        self::assertTrue($permissions->canAttach('task', $owner));

        $access->see = false;
        self::assertFalse($permissions->canAttach('task', $owner), 'Not seeing the record is not attaching to it.');

        $access->see = true;
        $access->attach = false;
        self::assertFalse($permissions->canAttach('task', $owner));
    }

    public function testNothingIsAllowedOnAKindNoModuleClaims(): void
    {
        $permissions = new AttachmentPermissions([new FakeAccess()]);
        $owner = Id::generate();

        self::assertFalse($permissions->canSee('invoice', $owner));
        self::assertFalse($permissions->canAttach('invoice', $owner));
        self::assertFalse($permissions->canDelete($this->attachmentOf('invoice', $owner)));
    }

    public function testDeletingFollowsTheModuleOfTheKind(): void
    {
        $access = new FakeAccess();
        $permissions = new AttachmentPermissions([$access]);
        $file = $this->attachmentOf('task', Id::generate());

        self::assertFalse($permissions->canDelete($file));
        $access->delete = true;
        self::assertTrue($permissions->canDelete($file));
    }

    public function testARefusalNamesItsReasonAndKeepsItsCause(): void
    {
        $cause = new \RuntimeException('The disk is full.');
        $refused = new AttachmentRefused(AttachmentRefused::FAILED, $cause);

        self::assertSame('failed', $refused->reason);
        self::assertSame('The attachment was refused: failed.', $refused->getMessage());
        self::assertSame(0, $refused->getCode());
        self::assertSame($cause, $refused->getPrevious());
        self::assertNull(new AttachmentRefused(AttachmentRefused::EMPTY)->getPrevious());
    }

    private function attachmentOf(string $kind, string $ownerId): Attachment
    {
        return new Attachment(Id::generate(), $kind, $ownerId, 'a.pdf', 'application/pdf', 3, hash('sha256', 'abc'), 'key', Id::generate(), new \DateTimeImmutable());
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
        return new UploadAttachment($this->attachments, $this->files, new AttachmentPermissions([$this->access]), $this->tenant(), $this->actor(), $this->correlation, new MockClock(), $limit);
    }

    private function delete(): DeleteAttachment
    {
        return new DeleteAttachment($this->attachments, new AttachmentPermissions([$this->access]), $this->actor(), $this->correlation, new MockClock());
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

    private function sparse(int $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        $this->paths[] = $path;
        $handle = fopen($path, 'wb');
        self::assertNotFalse($handle);
        ftruncate($handle, max(0, $bytes));
        fclose($handle);

        return $path;
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
    /** @var list<?string> the correlation id in force at each flush */
    public array $flushedUnder = [];

    public function __construct(private readonly ?AuditCorrelation $correlation = null)
    {
    }

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
        $this->flushedUnder[] = $this->correlation?->current();
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
