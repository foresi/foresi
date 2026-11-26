<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Domain;

use Foresi\Inbox\Domain\ThreadKey;
use PHPUnit\Framework\TestCase;

final class ThreadKeyTest extends TestCase
{
    private const string ITEM = '0192f1a4-0000-7000-8000-000000000001';

    public function testTheFirstReferenceComesFirst(): void
    {
        $key = ThreadKey::of(['root@client-a.example.com', 'reply-1@example.com'], 'reply-1@example.com', 'reply-2@client-a.example.com', self::ITEM);

        self::assertSame(hash('sha256', 'root@client-a.example.com'), $key->value);
    }

    public function testThenInReplyToThenTheOwnMessageIdThenTheItem(): void
    {
        self::assertSame(hash('sha256', 'root@example.com'), ThreadKey::of([], 'root@example.com', 'reply@example.com', self::ITEM)->value);
        self::assertSame(hash('sha256', 'root@example.com'), ThreadKey::of([], null, '<root@example.com>', self::ITEM)->value, 'Angle brackets are not part of the id.');
        self::assertSame(hash('sha256', self::ITEM), ThreadKey::of([], null, null, self::ITEM)->value);
        self::assertSame(hash('sha256', self::ITEM), ThreadKey::of([''], ' ', '', self::ITEM)->value, 'Empty ids count as none.');
    }

    public function testAReplyAndItsOriginalShareTheKey(): void
    {
        $original = ThreadKey::of([], null, 'root@example.com', self::ITEM);
        $reply = ThreadKey::of(['root@example.com'], 'root@example.com', 'reply@example.com', self::ITEM);
        $replyWithoutReferences = ThreadKey::of([], 'root@example.com', 'reply-2@example.com', self::ITEM);

        self::assertSame($original->value, $reply->value);
        self::assertSame($original->value, $replyWithoutReferences->value);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $original->value);
    }
}
