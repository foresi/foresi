<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Infrastructure\Catalogue;

use App\Events\Infrastructure\Catalogue\EventCatalogue;
use App\Events\Infrastructure\Catalogue\EventType;
use App\Events\Infrastructure\Catalogue\UnknownEventType;
use App\Shared\Domain\Module\ModuleGraph;
use App\Shared\Domain\Module\ModuleId;
use App\Shared\Domain\Module\ModuleKind;
use App\Shared\Domain\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue reads the contract events of the installed modules by
 * reflection. Each test scans a fixture module, a directory under
 * tests/Events/Fixtures that is named like a module of the real project.
 */
final class EventCatalogueTest extends TestCase
{
    public function testFindsEventClassesOfInstalledModulesOnly(): void
    {
        $catalogue = $this->scan('Valid');

        self::assertSame([], $catalogue->problems());
        self::assertSame(
            ['invoicing.invoice.issued@1', 'invoicing.invoice.issued@2', 'invoicing.invoice.paid@1'],
            array_map(static fn (EventType $type): string => $type->name.'@'.$type->version, $catalogue->all()),
        );
    }

    public function testEventsOutsideInstalledModulesAreNeverRead(): void
    {
        $namespace = 'App\Tests\Events\Fixtures\Project\src\\';
        $directory = \dirname(__DIR__, 2).'/Fixtures/Project';
        self::assertFileExists($directory.'/src/Stranger/Contract/Event/Stray.php');
        $graph = new ModuleGraph([new ModuleManifest(ModuleId::of('invoicing'), ModuleKind::Module, 'foresi/invoicing', $namespace.'Invoicing\\', [], [])]);

        $catalogue = EventCatalogue::forGraph($graph, $directory);

        self::assertSame(['invoicing.invoice.paid'], array_map(static fn (EventType $type): string => $type->name, $catalogue->all()));
        self::assertFalse($catalogue->has('stranger.thing.happened'));
        self::assertSame([], $catalogue->problems());
    }

    public function testPrefixMustEqualModuleId(): void
    {
        $catalogue = $this->scan('WrongPrefix');

        self::assertSame(['prefix:invoicing:party.invoice.issued'], $catalogue->problems());
        self::assertSame([], $catalogue->all());
    }

    public function testRejectsBadNames(): void
    {
        $catalogue = $this->scan('BadNames');

        self::assertSame([
            'name:'.\App\Tests\Events\Fixtures\BadNames\Contract\Event\Capitalised::class,
            'name:'.\App\Tests\Events\Fixtures\BadNames\Contract\Event\TwoParts::class,
        ], $catalogue->problems());
    }

    public function testDuplicateNameAndVersionIsAProblem(): void
    {
        $catalogue = $this->scan('Duplicate');

        self::assertSame(['duplicate:invoicing.invoice.issued@1'], $catalogue->problems());
    }

    public function testTwoVersionsMayCoexist(): void
    {
        $catalogue = $this->scan('Valid');

        self::assertSame(1, $catalogue->byName('invoicing.invoice.issued', 1)->version);
        self::assertSame(2, $catalogue->byName('invoicing.invoice.issued', 2)->version);
        self::assertTrue($catalogue->has('invoicing.invoice.issued'));
    }

    public function testClassMustBeFinalReadonlyAndImplementThePayload(): void
    {
        $catalogue = $this->scan('Shape');

        self::assertSame([
            'shape:'.\App\Tests\Events\Fixtures\Shape\Contract\Event\NoPayload::class,
            'shape:'.\App\Tests\Events\Fixtures\Shape\Contract\Event\NotFinal::class,
            'shape:'.\App\Tests\Events\Fixtures\Shape\Contract\Event\NotReadonly::class,
        ], $catalogue->problems());
    }

    public function testFactTypesAreLimited(): void
    {
        $catalogue = $this->scan('Facts');
        $namespace = 'App\Tests\Events\Fixtures\Facts\Contract\Event\\';

        self::assertSame([
            'fact:'.$namespace.'ArrayFact::value',
            'fact:'.$namespace.'DateFact::value',
            'fact:'.$namespace.'FloatFact::value',
            'fact:'.$namespace.'ForeignEnumFact::value',
            'fact:'.$namespace.'MoneyFact::value',
            'fact:'.$namespace.'StringListFact::value',
            'fact:'.$namespace.'UnionFact::value',
            'fact:'.$namespace.'UnitEnumFact::value',
        ], $catalogue->problems());
        self::assertSame(['invoicing.fact.good_enum', 'invoicing.fact.good_list'], array_map(static fn (EventType $type): string => $type->name, $catalogue->all()));
    }

    public function testFieldsAreSnakeCase(): void
    {
        $type = $this->scan('Valid')->byName('invoicing.invoice.issued', 1);

        self::assertSame(['invoice_id', 'line_count', 'delivered', 'due_on', 'kind', 'line_codes'], $type->fields);
        self::assertSame('invoicing', $type->module->value);
        self::assertSame(\App\Tests\Events\Fixtures\Valid\Contract\Event\InvoiceIssued::class, $type->class);
    }

    public function testFindsATypeByClass(): void
    {
        $catalogue = $this->scan('Valid');

        self::assertSame('invoicing.invoice.paid', $catalogue->byClass(\App\Tests\Events\Fixtures\Valid\Contract\Event\InvoicePaid::class)->name);
    }

    public function testUnknownTypesAreRefused(): void
    {
        $catalogue = $this->scan('Valid');

        try {
            $catalogue->byName('invoicing.invoice.issued', 3);
            self::fail('An unknown version must be refused.');
        } catch (UnknownEventType) {
            self::assertFalse($catalogue->has('invoicing.invoice.void'));
        }
        $this->expectException(UnknownEventType::class);
        $catalogue->byClass(\stdClass::class);
    }

    public function testMatchingExpandsModuleWildcards(): void
    {
        $catalogue = $this->scan('Valid');

        self::assertSame(['invoicing.invoice.issued', 'invoicing.invoice.paid'], $catalogue->matching(['invoicing.*']));
        self::assertSame(['invoicing.invoice.paid'], $catalogue->matching(['invoicing.invoice.paid', 'invoicing.invoice.paid']));
        self::assertSame([], $catalogue->matching(['party.*', 'invoicing.invoice.void']));
        self::assertSame(['invoicing.invoice.issued', 'invoicing.invoice.paid'], $catalogue->matching(['invoicing.invoice.paid', 'invoicing.*']));
    }

    private function scan(string $fixture): EventCatalogue
    {
        return EventCatalogue::scan([[
            ModuleId::of('invoicing'),
            'App\Tests\Events\Fixtures\\'.$fixture.'\\',
            \dirname(__DIR__, 2).'/Fixtures/'.$fixture,
        ]]);
    }
}
