<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Attachment\Infrastructure;

use App\Attachment\Application\AttachmentFileUnreadable;
use App\Attachment\Infrastructure\Storage\DiskAttachmentFiles;
use App\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

final class DiskAttachmentFilesTest extends TestCase
{
    private string $root;
    private string $source;
    private DiskAttachmentFiles $files;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/attachments-'.bin2hex(random_bytes(6));
        $this->source = (string) tempnam(sys_get_temp_dir(), 'source');
        file_put_contents($this->source, 'the bytes');
        $this->files = new DiskAttachmentFiles($this->root);
    }

    protected function tearDown(): void
    {
        @unlink($this->source);
        foreach ($this->found($this->root.'/*/*') as $file) {
            unlink($file);
        }
        foreach ($this->found($this->root.'/*') as $directory) {
            rmdir($directory);
        }
        @rmdir($this->root);
    }

    public function testAFileIsKeptPrivatelyUnderItsIdAndReadBackOnlyUnchanged(): void
    {
        $id = Id::generate();
        $umask = umask(0);
        try {
            $key = $this->files->store($id, $this->source);
        } finally {
            umask($umask);
        }

        self::assertSame($id, $key);
        self::assertSame(0o700, fileperms($this->root) & 0o777);
        self::assertSame(0o600, fileperms($this->root.'/'.$key) & 0o777);
        self::assertSame(['the bytes'], [file_get_contents($this->files->read($id, $key, hash('sha256', 'the bytes')))]);
        self::assertCount(1, $this->found($this->root.'/*'), 'No temporary file is left behind.');

        file_put_contents($this->root.'/'.$key, 'tampered');
        $this->expectException(AttachmentFileUnreadable::class);
        $this->files->read($id, $key, hash('sha256', 'the bytes'));
    }

    public function testAFileStoredUnderATenantStillReads(): void
    {
        $tenant = Id::generate();
        $id = Id::generate();
        mkdir($this->root.'/'.$tenant, 0o700, true);
        file_put_contents($this->root.'/'.$tenant.'/'.$id, 'the bytes');

        self::assertSame($this->root.'/'.$tenant.'/'.$id, $this->files->read($id, $tenant.'/'.$id, hash('sha256', 'the bytes')));
    }

    public function testAnotherAttachmentsKeyOrAMissingFileIsUnreadable(): void
    {
        $id = Id::generate();
        $key = $this->files->store($id, $this->source);
        $sha = hash('sha256', 'the bytes');

        $refused = [];
        foreach ([[Id::generate(), $key], [$id, $id.'/../'.$id], [$id, Id::generate().'/'.$id], [$id, $id.'/'.Id::generate()]] as [$reader, $candidate]) {
            try {
                $this->files->read($reader, $candidate, $sha);
                $refused[] = false;
            } catch (AttachmentFileUnreadable) {
                $refused[] = true;
            }
        }

        self::assertSame([true, true, true, true], $refused);
    }

    public function testLocatingAFileForAPartOfItChecksTheAttachmentButDoesNotHashIt(): void
    {
        $id = Id::generate();
        $key = $this->files->store($id, $this->source);
        file_put_contents($this->root.'/'.$key, 'tampered');

        self::assertSame($this->root.'/'.$key, $this->files->locate($id, $key));

        $refused = [];
        foreach ([[Id::generate(), $key], [$id, Id::generate()]] as [$reader, $candidate]) {
            try {
                $this->files->locate($reader, $candidate);
                $refused[] = false;
            } catch (AttachmentFileUnreadable) {
                $refused[] = true;
            }
        }
        self::assertSame([true, true], $refused);
    }

    public function testRemovingDeletesOnlyAFileNamedByAKey(): void
    {
        $key = $this->files->store(Id::generate(), $this->source);

        $this->files->remove('../'.$key);
        self::assertFileExists($this->root.'/'.$key);

        $this->files->remove($key);
        self::assertFileDoesNotExist($this->root.'/'.$key);
    }

    public function testAKeyIsMadeOfIdsOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->files->store('..', $this->source);
    }

    /**
     * @return list<string>
     */
    private function found(string $pattern): array
    {
        $found = glob($pattern);

        return false === $found ? [] : $found;
    }
}
