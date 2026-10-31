<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Application;

use App\Import\Application\Target\ImportTarget;
use App\Import\Application\Target\RecordSnapshot;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Application\Target\WriteResult;
use App\Import\Domain\BlankCells;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TargetRegistry::class)]
#[CoversClass(RecordSnapshot::class)]
final class TargetRegistryTest extends TestCase
{
    public function testFindsTargetsByKey(): void
    {
        $party = $this->target('party');
        $item = $this->target('item');
        $registry = new TargetRegistry([$party, $item]);

        self::assertSame($party, $registry->get('party'));
        self::assertSame($item, $registry->get('item'));
        self::assertSame([$party, $item], $registry->all());
    }

    public function testAcceptsAnyIterable(): void
    {
        $party = $this->target('party');
        $registry = new TargetRegistry((static function () use ($party): \Generator {
            yield $party;
        })());

        self::assertSame($party, $registry->get('party'));
    }

    public function testUnknownKeyIsRejected(): void
    {
        $registry = new TargetRegistry([$this->target('party')]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown import target "invoice".');
        $registry->get('invoice');
    }

    public function testEmptyRegistryHasNoTargets(): void
    {
        self::assertSame([], new TargetRegistry([])->all());
    }

    public function testSnapshotNoneIsNone(): void
    {
        self::assertTrue(RecordSnapshot::none()->isNone());
        self::assertFalse(new RecordSnapshot(['name' => 'Example Company'])->isNone());
        self::assertSame(['name' => 'Example Company'], (new RecordSnapshot(['name' => 'Example Company']))->data);
    }

    private function target(string $key): ImportTarget
    {
        return new readonly class($key) implements ImportTarget {
            public function __construct(private string $key)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function labelKey(): string
            {
                return 'import.target.'.$this->key;
            }

            public function fields(): array
            {
                return [];
            }

            public function matchKeys(): array
            {
                return [];
            }

            public function validate(array $values): array
            {
                return [];
            }

            public function match(string $matchKey, array $values): array
            {
                return [];
            }

            public function create(array $values): WriteResult
            {
                return new WriteResult(1, RecordSnapshot::none());
            }

            public function update(int $id, array $values, BlankCells $blankCells): WriteResult
            {
                return new WriteResult($id, RecordSnapshot::none());
            }

            public function restoreArchived(int $id): void
            {
            }

            public function archive(int $id): RecordSnapshot
            {
                return RecordSnapshot::none();
            }

            public function revert(int $id, RecordSnapshot $before): void
            {
            }

            public function activeIds(): iterable
            {
                return [];
            }

            public function export(array $ids): iterable
            {
                return [];
            }

            public function changedSince(int $id, RecordSnapshot $before, \DateTimeImmutable $since, string $exceptCorrelationId): bool
            {
                return false;
            }
        };
    }
}
