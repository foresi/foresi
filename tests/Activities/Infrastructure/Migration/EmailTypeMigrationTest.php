<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Activities\Infrastructure\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Foresi\Activities\Migrations\Version20261122230000;
use Foresi\Activities\Migrations\Version20261125090000;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The e-mail type is seeded as the fifth type, with no name of its own and no
 * time: on a fresh instance after the first four, and on one that has the four
 * already. An instance that has it keeps it as it is.
 */
final class EmailTypeMigrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    public function testTheEmailTypeIsSeededOnAFreshAndOnAnExistingInstance(): void
    {
        // A fresh instance runs both migrations, one after the other.
        $this->migration(Version20261122230000::class)->postUp(new Schema());
        $this->migration(Version20261125090000::class)->postUp(new Schema());

        $fresh = $this->types();
        self::assertSame([
            ['call', null, 'timed'],
            ['email', null, 'none'],
            ['meeting', null, 'timed'],
            ['note', null, 'none'],
            ['task', null, 'date'],
        ], $fresh);

        // An existing instance has the four; migrating again adds nothing more.
        $this->connection()->executeStatement("DELETE FROM activities_type WHERE type_key = 'email'");
        $this->migration(Version20261125090000::class)->postUp(new Schema());
        $this->migration(Version20261125090000::class)->postUp(new Schema());

        self::assertSame($fresh, $this->types());
    }

    public function testAnEmailTypeThatIsThereKeepsItsName(): void
    {
        $this->migration(Version20261122230000::class)->postUp(new Schema());
        $this->migration(Version20261125090000::class)->postUp(new Schema());
        $this->connection()->executeStatement("UPDATE activities_type SET name = 'Mail' WHERE type_key = 'email'");

        $this->migration(Version20261125090000::class)->postUp(new Schema());

        self::assertSame([['email', 'Mail', 'none']], array_values(array_filter($this->types(), static fn (array $type): bool => 'email' === $type[0])));
    }

    /**
     * Loaded by Doctrine from the module's migrations directory, as a deploy loads it.
     *
     * @param class-string<AbstractMigration> $version
     */
    private function migration(string $version): AbstractMigration
    {
        /** @var DependencyFactory $factory */
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        $class = $factory->getMigrationRepository()->getMigration(new Version($version))->getMigration()::class;

        return new $class($this->connection(), $factory->getLogger());
    }

    /**
     * @return list<array{mixed, mixed, mixed}> key, name and timing, by key
     */
    private function types(): array
    {
        return array_map(static fn (array $row): array => [$row['type_key'], $row['name'], $row['timing']], $this->connection()->fetchAllAssociative('SELECT type_key, name, timing FROM activities_type ORDER BY type_key'));
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
