<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure;

use App\Import\Infrastructure\LocalImportFiles;
use App\Tenancy\Domain\TenantId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LocalImportFiles::class)]
final class LocalImportFilesTest extends TestCase
{
    private string $root;
    private string $source;
    private LocalImportFiles $files;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir().'/foresi-import-files-'.bin2hex(random_bytes(6));
        mkdir($base);
        $this->root = $base.'/imports';
        $this->source = $base.'/upload.tmp';
        file_put_contents($this->source, "name;email\nExample Company;testing@example.com\n");
        $this->files = new LocalImportFiles($this->root);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove(\dirname($this->root));
    }

    public function testStoreCopiesTheFileIntoTheTenantDirectoryWithMode0600(): void
    {
        $tenant = TenantId::generate();

        $key = $this->files->store($tenant, $this->source, 'contacts.csv');

        self::assertStringStartsWith($tenant->toString().'/', $key);
        self::assertTrue(Uuid::isValid(substr($key, \strlen($tenant->toString()) + 1)));
        $path = $this->files->path($key);
        self::assertSame($this->root.'/'.$key, $path);
        self::assertSame(file_get_contents($this->source), file_get_contents($path));
        self::assertSame('0600', substr(\sprintf('%o', fileperms($path)), -4));
        self::assertSame('0700', substr(\sprintf('%o', fileperms($this->root.'/'.$tenant->toString())), -4));
        self::assertFileExists($this->source);
    }

    public function testTheOriginalNameIsNotPartOfThePath(): void
    {
        $key = $this->files->store(TenantId::generate(), $this->source, '../../etc/passwd.csv');

        self::assertStringNotContainsString('passwd', $key);
        self::assertStringNotContainsString('..', $key);
    }

    public function testEachStoreGetsItsOwnKey(): void
    {
        $tenant = TenantId::generate();

        self::assertNotSame($this->files->store($tenant, $this->source, 'a.csv'), $this->files->store($tenant, $this->source, 'a.csv'));
    }

    public function testStoringAMissingFileFailsAndLeavesNothingBehind(): void
    {
        $tenant = TenantId::generate();

        try {
            $this->files->store($tenant, $this->root.'/missing.tmp', 'a.csv');
            self::fail('Expected RuntimeException.');
        } catch (\RuntimeException) {
            self::assertSame([], glob($this->root.'/'.$tenant->toString().'/*'));
        }
    }

    public function testDeleteRemovesTheFileAndIsIdempotent(): void
    {
        $key = $this->files->store(TenantId::generate(), $this->source, 'a.csv');
        $path = $this->files->path($key);

        $this->files->delete($key);
        $this->files->delete($key);

        self::assertFileDoesNotExist($path);
    }

    public function testPathOfAMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->files->path(TenantId::generate()->toString().'/'.Uuid::v4()->toRfc4122());
    }

    #[DataProvider('malformedKeys')]
    public function testMalformedKeysAreRejected(string $key): void
    {
        $this->expectException(\RuntimeException::class);
        $this->files->path($key);
    }

    #[DataProvider('malformedKeys')]
    public function testMalformedKeysCannotDeleteAnything(string $key): void
    {
        $this->expectException(\RuntimeException::class);
        $this->files->delete($key);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'traversal' => ['../../etc/passwd'];
        yield 'traversal in the uuid slot' => ['11111111-1111-7111-8111-111111111111/../../../etc/passwd'];
        yield 'one part' => ['11111111-1111-7111-8111-111111111111'];
        yield 'trailing slash' => ['11111111-1111-7111-8111-111111111111/22222222-2222-4222-8222-222222222222/'];
        yield 'prefix before the key' => ['x11111111-1111-7111-8111-111111111111/22222222-2222-4222-8222-222222222222'];
        yield 'newline at the end' => ["11111111-1111-7111-8111-111111111111/22222222-2222-4222-8222-222222222222\n"];
    }
}
