<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Audit\Domain;

use App\Audit\Domain\AuditAction;
use App\Audit\Domain\AuditEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditEntryTest extends TestCase
{
    public function testExposesWhatItWasCreatedWith(): void
    {
        $at = new \DateTimeImmutable('2026-10-29T10:00:00+00:00');
        $entry = new AuditEntry('App\\Thing', '7', AuditAction::Updated, ['name' => ['a', 'b']], 3, $at);

        self::assertNull($entry->id());
        self::assertSame('App\\Thing', $entry->entityClass());
        self::assertSame('7', $entry->entityId());
        self::assertSame(AuditAction::Updated, $entry->action());
        self::assertSame(['name' => ['a', 'b']], $entry->changes());
        self::assertSame(3, $entry->userId());
        self::assertSame($at, $entry->occurredAt());
    }

    public function testTenantIsStampedOnPersist(): void
    {
        $this->expectException(\LogicException::class);
        new AuditEntry('App\\Thing', '7', AuditAction::Created, [], null, new \DateTimeImmutable())->tenantId();
    }

    /**
     * @param array<string, array<mixed>> $changes
     */
    #[DataProvider('invalid')]
    public function testRejectsIncompleteEntries(string $class, string $id, array $changes): void
    {
        $this->expectException(\DomainException::class);
        new AuditEntry($class, $id, AuditAction::Created, $changes, null, new \DateTimeImmutable());
    }

    /**
     * @return iterable<string, array{string, string, array<string, array<mixed>>}>
     */
    public static function invalid(): iterable
    {
        yield 'no class' => ['', '1', []];
        yield 'no id' => ['App\\Thing', '', []];
        yield 'pair with one value' => ['App\\Thing', '1', ['name' => ['a']]];
        yield 'pair with three values' => ['App\\Thing', '1', ['name' => ['a', 'b', 'c']]];
        yield 'keyed pair' => ['App\\Thing', '1', ['name' => ['old' => 'a', 'new' => 'b']]];
    }
}
