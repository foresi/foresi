<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Migration;

use Foresi\Shared\Infrastructure\Console\RebaseMigrationsCommand;
use Foresi\Shared\Infrastructure\Migration\GlobalMigrationHistory;
use Foresi\Tests\Shared\Support\RebaseTestCase;
use Symfony\Component\Console\Command\Command;

/**
 * A database on the global migration history moves onto the module baselines
 * by its version rows alone, and only from the one state that allows it. The
 * full proof against the last release with the history is
 * bin/migration-rebase-proof in CI.
 */
final class MigrationRebaseTest extends RebaseTestCase
{
    public function testADatabaseOnTheGlobalHistoryMovesOntoTheBaselines(): void
    {
        $tables = $this->tables();
        $this->onGlobalHistory();
        self::assertSame(RebaseMigrationsCommand::PENDING, $this->console('app:migrations:rebase', ['--check' => true])->getStatusCode());

        $rebase = $this->console('app:migrations:rebase');

        self::assertSame(Command::SUCCESS, $rebase->getStatusCode(), $rebase->getDisplay());
        self::assertStringContainsString('Replaced the 108 versions', $rebase->getDisplay());
        self::assertSame($this->baselines(), $this->versions());
        // What ops/deploy.sh runs next: the migrations after the baselines, which create what the database never had.
        self::assertSame(Command::SUCCESS, $this->console('doctrine:migrations:migrate')->getStatusCode());
        self::assertSame($tables, $this->tables());
        self::assertSame(Command::SUCCESS, $this->console('doctrine:migrations:up-to-date')->getStatusCode());
        self::assertSame(Command::SUCCESS, $this->console('app:migrations:rebase', ['--check' => true])->getStatusCode());
    }

    public function testADatabaseOnTheBaselinesIsLeftAlone(): void
    {
        $versions = $this->versions();

        $rebase = $this->console('app:migrations:rebase');

        self::assertSame(Command::SUCCESS, $rebase->getStatusCode());
        self::assertStringContainsString('already on the module baselines', $rebase->getDisplay());
        self::assertSame($versions, $this->versions());
        self::assertStringContainsString('rebased', $this->console('app:migrations:rebase', ['--check' => true])->getDisplay());
    }

    public function testANewDatabaseIsRefused(): void
    {
        $this->db->executeStatement('DELETE FROM doctrine_migration_versions');

        self::assertSame(Command::SUCCESS, $this->console('app:migrations:rebase', ['--check' => true])->getStatusCode());
        $rebase = $this->console('app:migrations:rebase');

        self::assertSame(Command::FAILURE, $rebase->getStatusCode());
        self::assertStringContainsString('executed no migration', $rebase->getDisplay());
    }

    public function testAMissingGlobalVersionIsRefused(): void
    {
        $this->onGlobalHistory();
        $this->db->delete('doctrine_migration_versions', ['version' => GlobalMigrationHistory::VERSIONS[5]]);

        $this->assertRefused(GlobalMigrationHistory::VERSIONS[5]);
    }

    public function testAVersionOutsideTheGlobalHistoryIsRefused(): void
    {
        $this->onGlobalHistory();
        $this->db->insert('doctrine_migration_versions', ['version' => 'DoctrineMigrations\Version20991231000000']);

        $this->assertRefused('DoctrineMigrations\Version20991231000000');
    }

    public function testASchemaOutOfSyncWithTheMappingIsRefused(): void
    {
        $this->onGlobalHistory();
        $this->db->executeStatement('DROP INDEX idx_saved_view_list');

        // The check reads the version rows only, so the deploy leaves such a database alone too.
        $this->assertRefused('not in sync with the mapping', RebaseMigrationsCommand::PENDING);
    }
}
