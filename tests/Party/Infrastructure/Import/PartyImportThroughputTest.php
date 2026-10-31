<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure\Import;

use App\Import\Application\CheckImport;
use App\Import\Application\ConfigureImport;
use App\Import\Application\Imports;
use App\Import\Application\RunImport;
use App\Import\Application\StartImport;
use App\Import\Application\UndoImport;
use App\Import\Domain\BlankCells;
use App\Import\Domain\DateOrder;
use App\Import\Domain\DecimalSeparator;
use App\Import\Domain\Import;
use App\Import\Domain\ImportMode;
use App\Import\Domain\ImportOptions;
use App\Import\Domain\ImportState;
use App\Party\Infrastructure\Import\PartyImportTarget;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Import\Support\RunMessages;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Measures an import of many contacts end to end. Not part of the default
 * suite (see phpunit.dist.xml); run it with --group throughput, after clearing
 * var/cache/test (the kernel runs without debug and so never rebuilds a stale
 * container). The rows are
 * generated here, example.com data only, and the rates are written to
 * var/import-throughput.txt.
 */
#[Group('throughput')]
final class PartyImportThroughputTest extends KernelTestCase
{
    private const int DEFAULT_ROWS = 10_000;
    private const float MINIMUM_RUN_RATE = 150.0;

    public function testTenThousandContacts(): void
    {
        $rows = filter_var($_SERVER['IMPORT_THROUGHPUT_ROWS'] ?? self::DEFAULT_ROWS, \FILTER_VALIDATE_INT);
        self::assertIsInt($rows);
        // Without the debug collectors of the test kernel, which keep every query in memory.
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
        $container->get(TenantContext::class)->switchTo(TenantId::generate());
        $imports = $container->get(Imports::class);

        $dir = sys_get_temp_dir().'/foresi-throughput-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $files = ['create' => $dir.'/contacts.csv', 'update' => $dir.'/changed.csv'];
        file_put_contents($files['create'], $this->csv($rows, false));
        file_put_contents($files['update'], $this->csv($rows, true));
        $line = $em->getConnection()->getDatabasePlatform()::class.' rows '.$rows;

        try {
            // First every row creates a contact, then a changed file updates them, matched by e-mail; then that update is undone.
            foreach (['create' => null, 'update' => 'email'] as $pass => $matchKey) {
                // The audit log keeps whole seconds: a run that starts in the second the previous one ended would see its entries as foreign.
                if ('update' === $pass) {
                    sleep(1);
                }
                $times = $this->pass($files[$pass], $matchKey);
                $last = $em->getConnection()->fetchOne('SELECT MAX(id) FROM import');
                self::assertIsInt($last);
                $import = $imports->findFresh($last);
                self::assertInstanceOf(Import::class, $import);
                self::assertSame(ImportState::Finished, $import->state());
                self::assertSame($rows, 'create' === $pass ? $import->created() : $import->updated());
                $line .= \sprintf(', %s: read %.1f s, check %.1f s (%.0f rows/s), run %.1f s (%.0f rows/s)', $pass, $times['read'], $times['check'], $rows / $times['check'], $times['run'], $rows / $times['run']);
                self::assertGreaterThanOrEqual(self::MINIMUM_RUN_RATE, $rows / $times['run'], $line);
            }
            $id = $em->getConnection()->fetchOne('SELECT MAX(id) FROM import');
            self::assertIsInt($id);
            $undo = $this->time(function () use ($id): void {
                self::getContainer()->get(UndoImport::class)->undo($id);
                $this->worker()->run();
            });
            $import = $imports->findFresh($id);
            self::assertInstanceOf(Import::class, $import);
            self::assertSame([ImportState::Undone, $rows, 0], [$import->state(), $import->reverted(), $import->changedAfterImport()]);
            $line .= \sprintf(', undo of the update: %.1f s (%.0f rows/s)', $undo, $rows / $undo);
            self::assertGreaterThanOrEqual(self::MINIMUM_RUN_RATE, $rows / $undo, $line);
        } finally {
            new Filesystem()->remove($dir);
            file_put_contents(self::getContainer()->getParameter('kernel.project_dir').'/var/import-throughput.txt', $line."\n", \FILE_APPEND);
        }
    }

    /**
     * @return array{read: float, check: float, run: float} seconds
     */
    private function pass(string $path, ?string $matchKey): array
    {
        $container = self::getContainer();
        $worker = $this->worker();
        $imports = $container->get(Imports::class);
        $id = 0;
        // The upload is moved into the import store, so each pass gets its own copy.
        $upload = $path.'.'.bin2hex(random_bytes(4));
        copy($path, $upload);

        $read = $this->time(static function () use ($container, $worker, $upload, &$id): void {
            $id = $container->get(StartImport::class)->start(PartyImportTarget::KEY, $upload, 'contacts.csv', 1);
            $worker->run();
        });
        $import = $imports->findFresh($id);
        self::assertInstanceOf(Import::class, $import);
        $options = new ImportOptions(ImportMode::Update, null, $matchKey ?? $import->options()->matchKey, BlankCells::Keep, DateOrder::DayMonthYear, DecimalSeparator::Comma, null);
        $container->get(ConfigureImport::class)->configure($id, $import->mapping(), $options);

        $check = $this->time(static function () use ($container, $worker, $id): void {
            $container->get(CheckImport::class)->check($id);
            $worker->run();
        });
        $run = $this->time(static function () use ($container, $worker, $id): void {
            $container->get(RunImport::class)->run($id);
            $worker->run();
        });

        return ['read' => $read, 'check' => $check, 'run' => $run];
    }

    /**
     * An iDoklad export of companies with a VAT id, a contact person, a copy
     * e-mail and a domestic bank account, and of persons without them.
     */
    private function csv(int $rows, bool $changed): string
    {
        $csv = "\u{FEFF}Firma,IČ,DIČ,Ulice,PSČ,Město,Stát,E-mailová adresa,Další příjemci,Telefon,www,Sleva,Splatnost,Nastavení odesílaní upomínek,Titul,Jméno,Příjmení,Mobil,Číslo účtu,Kód banky,IBAN,SWIFT\n";
        for ($i = 1; $i <= $rows; ++$i) {
            $company = 0 === $i % 2;
            $csv .= implode(',', [
                $company ? 'Example Company '.$i : 'Example Person '.$i,
                '',
                $company ? \sprintf('CZ99%08d', $i) : '',
                'Example Street '.$i,
                '110 00',
                'Example City',
                'Česká republika',
                'contact'.$i.'@example.com',
                $company ? ($changed ? 'billing'.$i.'@example.com second'.$i.'@example.com' : 'billing'.$i.'@example.com') : '',
                $changed ? '+420 111 222 333' : '+420 123 456 789',
                'https://www.example.com',
                '0.00',
                '14',
                'Ano',
                '',
                $company ? 'Jana' : 'Example',
                $company ? 'Example '.$i : 'Person '.$i,
                '+420 987 654 321',
                $company ? (string) (100000 + $i) : '',
                $company ? '0100' : '',
                '',
                '',
            ])."\n";
        }

        return $csv;
    }

    private function worker(): RunMessages
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return new RunMessages($transport, self::getContainer()->get(MessageBusInterface::class));
    }

    private function time(callable $work): float
    {
        $start = hrtime(true);
        $work();

        return (hrtime(true) - $start) / 1e9;
    }
}
