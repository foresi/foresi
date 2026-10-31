<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Application;

use App\Import\Application\ImportFiles;
use App\Import\Application\ImportJobs;
use App\Import\Application\Imports;
use App\Import\Application\ProfileRepository;
use App\Import\Application\StartImport;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Domain\Import;
use App\Import\Infrastructure\LocalImportFiles;
use App\Party\Infrastructure\Import\PartyImportTarget;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class StartImportTest extends KernelTestCase
{
    private string $root;
    private string $upload;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->root = sys_get_temp_dir().'/foresi-start-import-'.bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->upload = $this->root.'/upload.csv';
        file_put_contents($this->upload, "Firma\nExample Company s.r.o.\n");
        self::getContainer()->get(TenantContext::class)->switchTo(TenantId::generate());
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        parent::tearDown();
    }

    public function testTheStoredFileIsRemovedWhenTheImportCannotBeStored(): void
    {
        $files = new LocalImportFiles($this->root.'/store');
        $imports = self::createStub(Imports::class);
        $imports->method('add')->willThrowException(new \RuntimeException('The database refused the import.'));

        try {
            $this->start($files, $imports)->start(PartyImportTarget::KEY, $this->upload, 'contacts.csv', '019a0000-0000-7000-8000-000000000001');
            self::fail('The failure must reach the caller.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The database refused the import.', $failure->getMessage());
        }

        self::assertSame([], $this->storedFiles());
    }

    public function testTheNameIsNormalisedBeforeTheImportIsStored(): void
    {
        $stored = null;
        $imports = $this->createMock(Imports::class);
        $imports->expects(self::once())->method('add')->willReturnCallback(static function (Import $import) use (&$stored): never {
            $stored = $import->originalName();
            throw new \RuntimeException('Stop here.');
        });

        try {
            $this->start(new LocalImportFiles($this->root.'/store'), $imports)->start(PartyImportTarget::KEY, $this->upload, 'C:\\fakepath\\'.str_repeat('x', 300).'.csv', '019a0000-0000-7000-8000-000000000001');
        } catch (\RuntimeException) {
        }

        self::assertIsString($stored);
        self::assertSame(255, mb_strlen($stored));
        self::assertStringEndsWith('x.csv', $stored);
    }

    private function start(ImportFiles $files, Imports $imports): StartImport
    {
        $container = self::getContainer();

        return new StartImport(
            $container->get(TargetRegistry::class),
            $files,
            $imports,
            self::createStub(ProfileRepository::class),
            self::createStub(ImportJobs::class),
            $container->get(TenantContext::class),
            $container->get(ClockInterface::class),
        );
    }

    /**
     * @return list<string>
     */
    private function storedFiles(): array
    {
        $found = [];
        if (is_dir($this->root.'/store')) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root.'/store', \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $found[] = $file->getPathname();
                }
            }
        }

        return $found;
    }
}
