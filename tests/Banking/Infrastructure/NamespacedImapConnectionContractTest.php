<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Infrastructure;

use App\Banking\Infrastructure\Imap\NamespacedImapConnection;
use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\Data;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Result;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use PHPUnit\Framework\TestCase;

/**
 * NamespacedImapConnection extends the library's connection and leans on parts
 * of it that are protected or internal, which a minor release may change
 * without notice. Composer pins the library to its patch releases; this test
 * names every part the subclass uses, read by reflection as the code finds them
 * at run time, so an update that moves one fails here, before a mailbox does.
 */
final class NamespacedImapConnectionContractTest extends TestCase
{
    public function testTheSubclassExtendsTheLibrarysConnection(): void
    {
        self::assertTrue($this->type(NamespacedImapConnection::class)->isSubclassOf(ImapConnection::class));
        self::assertTrue($this->type(ImapCommandException::class)->isSubclassOf(\Throwable::class));
    }

    public function testSendWritesACommandAndGivesItsTag(): void
    {
        $send = $this->type(ImapConnection::class)->getMethod('send');

        self::assertTrue($send->isPublic());
        self::assertSame(['name', 'tokens', 'tag'], array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $send->getParameters()));
        self::assertTrue($send->getParameters()[2]->isPassedByReference());
    }

    public function testAssertTaggedResponseIsReachableFromTheSubclass(): void
    {
        $assert = $this->type(ImapConnection::class)->getMethod('assertTaggedResponse');

        self::assertFalse($assert->isPrivate());
        self::assertSame('tag', $assert->getParameters()[0]->getName());
        self::assertSame(TaggedResponse::class, (string) $assert->getReturnType());
    }

    public function testTheLastResultIsAProtectedProperty(): void
    {
        $result = $this->type(ImapConnection::class)->getProperty('result');

        self::assertTrue($result->isProtected());
        self::assertSame('?'.Result::class, (string) $result->getType());
        self::assertSame(ResponseCollection::class, (string) $this->type(Result::class)->getMethod('responses')->getReturnType());
        self::assertTrue($this->type(ResponseCollection::class)->hasMethod('untagged'));
    }

    public function testTheResponsePartsTheNamespaceIsReadFrom(): void
    {
        self::assertTrue($this->type(UntaggedResponse::class)->hasMethod('type'));
        self::assertTrue($this->type(UntaggedResponse::class)->hasMethod('tokenAt'));
        self::assertTrue($this->type(ListData::class)->isSubclassOf(Data::class));
        self::assertTrue($this->type(Data::class)->hasMethod('first'));
        self::assertTrue($this->type(Data::class)->hasMethod('tokens'));
        self::assertTrue($this->type(Token::class)->hasMethod('is'));
        self::assertTrue($this->type(Token::class)->getProperty('value')->isPublic());
    }

    /**
     * @param class-string $class
     *
     * @return \ReflectionClass<object>
     */
    private function type(string $class): \ReflectionClass
    {
        return new \ReflectionClass($class);
    }
}
