<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Domain;

use Foresi\Identity\Domain\ApiScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiScopeTest extends TestCase
{
    #[DataProvider('grants')]
    public function testAScopeGrantsWhatItCovers(string $held, string $needed, bool $granted): void
    {
        self::assertSame($granted, ApiScope::from($held)->grants(ApiScope::from($needed)));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function grants(): iterable
    {
        yield 'write grants a resource write' => ['write', 'contacts:write', true];
        yield 'write grants read' => ['write', 'read', true];
        yield 'read grants a resource read' => ['read', 'invoices:read', true];
        yield 'read does not write' => ['read', 'contacts:write', false];
        yield 'read does not grant write' => ['read', 'write', false];
        yield 'a resource write reads it' => ['contacts:write', 'contacts:read', true];
        yield 'a resource write writes it' => ['contacts:write', 'contacts:write', true];
        yield 'a resource write is not another resource' => ['contacts:write', 'invoices:read', false];
        yield 'a resource read does not write it' => ['contacts:read', 'contacts:write', false];
        yield 'a resource read is not everything' => ['contacts:read', 'read', false];
        yield 'a resource write is not everything' => ['contacts:write', 'write', false];
    }

    public function testTheShapeOfAScopeIsChecked(): void
    {
        self::assertSame('contacts', ApiScope::from('contacts:write')->resource());
        self::assertNull(ApiScope::read()->resource());
        self::assertTrue(ApiScope::of('contacts', true)->equals(ApiScope::from('contacts:write')));
        foreach (['', 'admin', 'contacts', 'contacts:delete', 'Contacts:read', ':read', 'contacts:read:x'] as $value) {
            self::assertNull(ApiScope::tryFrom($value), $value);
        }
        $this->expectException(\InvalidArgumentException::class);
        ApiScope::from('admin');
    }
}
