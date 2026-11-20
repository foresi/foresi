<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Migration;

use Doctrine\Migrations\Version\Version;
use Foresi\Shared\Domain\Module\ModuleGraph;
use Foresi\Shared\Domain\Module\ModuleId;
use Foresi\Shared\Domain\Module\ModuleKind;
use Foresi\Shared\Domain\Module\ModuleManifest;
use Foresi\Shared\Infrastructure\Migration\ModuleVersionComparator;
use PHPUnit\Framework\TestCase;

/**
 * Migrations of all modules in one history: by timestamp, then a required
 * module first.
 */
final class ModuleVersionComparatorTest extends TestCase
{
    public function testTheBaselinesOfOneTimestampRunARequiredModuleFirst(): void
    {
        self::assertSame([
            \Foresi\Shared\Migrations\Version20261120000000::class,
            \Foresi\Party\Migrations\Version20261120000000::class,
            \Foresi\Invoicing\Migrations\Version20261120000000::class,
        ], $this->sorted([
            \Foresi\Invoicing\Migrations\Version20261120000000::class,
            \Foresi\Party\Migrations\Version20261120000000::class,
            \Foresi\Shared\Migrations\Version20261120000000::class,
        ]));
    }

    public function testAnOlderTimestampComesFirstWhateverTheModule(): void
    {
        self::assertSame([
            \Foresi\Invoicing\Migrations\Version20261120000000::class,
            'Foresi\Shared\Migrations\Version20261201000001',
            'Foresi\Party\Migrations\Version20261201000002',
        ], $this->sorted([
            'Foresi\Party\Migrations\Version20261201000002',
            'Foresi\Shared\Migrations\Version20261201000001',
            \Foresi\Invoicing\Migrations\Version20261120000000::class,
        ]));
    }

    public function testALongerTimestampIsNewer(): void
    {
        self::assertSame([
            \Foresi\Party\Migrations\Version20261120000000::class,
            'Foresi\Shared\Migrations\Version202611200000001',
        ], $this->sorted([
            'Foresi\Shared\Migrations\Version202611200000001',
            \Foresi\Party\Migrations\Version20261120000000::class,
        ]));
    }

    public function testAVersionOfNoInstalledModuleComesAfterTheModulesOfItsTimestamp(): void
    {
        self::assertSame([
            \Foresi\Invoicing\Migrations\Version20261120000000::class,
            'Acme\Migrations\Version20261120000000',
            'App\Migrations\Version20261120000000',
            'Foresi\Shared\Migrations\Version20261121000000',
        ], $this->sorted([
            'Foresi\Shared\Migrations\Version20261121000000',
            'App\Migrations\Version20261120000000',
            \Foresi\Invoicing\Migrations\Version20261120000000::class,
            'Acme\Migrations\Version20261120000000',
        ]));
    }

    public function testANameWithoutATimestampComesLast(): void
    {
        self::assertSame([
            \Foresi\Party\Migrations\Version20261120000000::class,
            'Version20261121000000',
            'Foresi\Shared\Migrations\Baseline',
        ], $this->sorted([
            'Foresi\Shared\Migrations\Baseline',
            'Version20261121000000',
            \Foresi\Party\Migrations\Version20261120000000::class,
        ]));
    }

    public function testAVersionEqualsItself(): void
    {
        $version = new Version(\Foresi\Party\Migrations\Version20261120000000::class);

        self::assertSame(0, $this->comparator()->compare($version, new Version((string) $version)));
    }

    /**
     * @param list<string> $versions
     *
     * @return list<string>
     */
    private function sorted(array $versions): array
    {
        $comparator = $this->comparator();
        $versions = array_map(static fn (string $name): Version => new Version($name), $versions);
        usort($versions, $comparator->compare(...));

        return array_map(static fn (Version $version): string => (string) $version, $versions);
    }

    private function comparator(): ModuleVersionComparator
    {
        return new ModuleVersionComparator(new ModuleGraph([
            $this->module('shared', 'Shared', []),
            // "invoicing" sorts before "party" by id, but requires it.
            $this->module('invoicing', 'Invoicing', ['party', 'shared']),
            $this->module('party', 'Party', ['shared']),
        ]));
    }

    /**
     * @param list<string> $requires
     */
    private function module(string $id, string $segment, array $requires): ModuleManifest
    {
        return new ModuleManifest(
            ModuleId::of($id),
            ModuleKind::Module,
            'foresi/'.$id,
            'Foresi\\'.$segment.'\\',
            array_map(ModuleId::of(...), $requires),
            [],
        );
    }
}
