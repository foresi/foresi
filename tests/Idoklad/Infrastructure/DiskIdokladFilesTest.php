<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Idoklad\Infrastructure;

use App\Idoklad\Application\IdokladFileUnreadable;
use App\Idoklad\Infrastructure\Files\DiskIdokladFiles;
use App\Shared\Domain\Id;
use PHPUnit\Framework\TestCase;

final class DiskIdokladFilesTest extends TestCase
{
    private string $root;
    private DiskIdokladFiles $files;
    private string $tenant;
    private string $record;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/foresi-idoklad-'.bin2hex(random_bytes(4));
        $this->files = new DiskIdokladFiles($this->root);
        $this->tenant = Id::generate();
        $this->record = Id::generate();
    }

    protected function tearDown(): void
    {
        foreach ($this->glob($this->root.'/*/*') as $file) {
            unlink($file);
        }
        foreach ($this->glob($this->root.'/*') as $directory) {
            rmdir($directory);
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function testAStoredPdfIsPrivateAndReadsBackWithItsHash(): void
    {
        $key = $this->files->store($this->tenant, $this->record, '%PDF-1.7');

        self::assertSame($this->tenant.'/'.$this->record.'.pdf', $key);
        self::assertSame(0o700, fileperms($this->root.'/'.$this->tenant) & 0o777);
        self::assertSame(0o600, fileperms($this->root.'/'.$key) & 0o777);
        self::assertSame('%PDF-1.7', $this->files->read($this->tenant, $key, hash('sha256', '%PDF-1.7')));
        self::assertSame([$this->record.'.pdf'], array_map(basename(...), $this->glob($this->root.'/'.$this->tenant.'/*')));
    }

    public function testOnlyIdsNameAFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->files->store($this->tenant, '../escape', '%PDF-1.7');
    }

    public function testAnotherTenantsKeyIsRefused(): void
    {
        $key = $this->files->store($this->tenant, $this->record, '%PDF-1.7');

        $this->expectException(IdokladFileUnreadable::class);
        $this->expectExceptionMessageIsOrContains('The iDoklad document does not belong to the tenant.');
        $this->files->read(Id::generate(), $key, hash('sha256', '%PDF-1.7'));
    }

    public function testAMissingFileIsUnreadable(): void
    {
        $this->expectException(IdokladFileUnreadable::class);
        $this->expectExceptionMessageIsOrContains('The iDoklad document is missing.');
        $this->files->read($this->tenant, $this->tenant.'/'.$this->record.'.pdf', hash('sha256', ''));
    }

    public function testAChangedFileIsUnreadable(): void
    {
        $key = $this->files->store($this->tenant, $this->record, '%PDF-1.7');
        file_put_contents($this->root.'/'.$key, '%PDF-changed');

        $this->expectException(IdokladFileUnreadable::class);
        $this->expectExceptionMessageIsOrContains('The iDoklad document changed after it was pulled.');
        $this->files->read($this->tenant, $key, hash('sha256', '%PDF-1.7'));
    }

    /**
     * @return list<string>
     */
    private function glob(string $pattern): array
    {
        $paths = glob($pattern);

        return false === $paths ? [] : $paths;
    }
}
