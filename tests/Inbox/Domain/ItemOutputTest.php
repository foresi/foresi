<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Domain;

use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemOutput;
use Foresi\Inbox\Domain\ThreadKey;
use Foresi\Shared\Domain\Id;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An output names the kind of record it made: a key of lower-case letters, digits and underscores.
 */
final class ItemOutputTest extends TestCase
{
    public function testAKindIsAKeyOfUpToFortyCharacters(): void
    {
        self::assertSame('contact', $this->makeOutput('contact')->kind());
        self::assertSame('a_1', $this->makeOutput('a_1')->kind());
        self::assertSame(40, \strlen($this->makeOutput(str_repeat('a', 40))->kind()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badKinds(): iterable
    {
        yield 'a prefix in front' => ['Bad-contact'];
        yield 'a tail behind' => ['contact!'];
        yield 'a space in front' => [' contact'];
        yield 'one over forty' => [str_repeat('a', 41)];
        yield 'a digit first' => ['1contact'];
        yield 'empty' => [''];
    }

    #[DataProvider('badKinds')]
    public function testAnythingElseIsRefused(string $kind): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeOutput($kind);
    }

    private function makeOutput(string $kind): ItemOutput
    {
        $id = Id::generate();
        $item = new InboxItem($id, Id::generate(), Id::generate(), new \DateTimeImmutable(), false, null, ThreadKey::of([], null, 'm@example.com', $id), null);

        return new ItemOutput($item, $kind, Id::generate(), new \DateTimeImmutable(), Id::generate());
    }
}
