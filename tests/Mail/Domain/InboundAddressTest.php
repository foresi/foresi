<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Domain;

use Foresi\Mail\Domain\InboundAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An inbound address keeps its token only as a hash, of one exact shape.
 */
final class InboundAddressTest extends TestCase
{
    public function testItKeepsTheHashOfItsToken(): void
    {
        $hash = hash('sha256', 'token');

        self::assertSame($hash, $this->open($hash)->tokenHash());
    }

    #[DataProvider('badHashes')]
    public function testATokenHashIsSixtyFourLowercaseHexDigits(string $hash): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageIsOrContains('A token hash is 64 lowercase hex digits.');

        $this->open($hash);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badHashes(): iterable
    {
        $hash = hash('sha256', 'token');

        yield 'something before' => ['x'.$hash];
        yield 'something after' => [$hash.'x'];
        yield 'a line end after' => [$hash."\n"];
        yield 'upper case' => [strtoupper($hash)];
    }

    private function open(string $hash): InboundAddress
    {
        return new InboundAddress('bank_feed', null, 'Bank feed', $hash, 'sealed', new \DateTimeImmutable('2026-11-02T09:00:00+01:00'));
    }
}
