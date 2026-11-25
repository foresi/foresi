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

    public function testAPurposeIsTrimmedAndFortyCharactersAreEnough(): void
    {
        $edge = str_repeat('p', InboundAddress::MAX_PURPOSE);

        self::assertSame($edge, $this->open(hash('sha256', 'token'), purpose: '  '.$edge.'  ')->purpose());
    }

    #[DataProvider('badPurposes')]
    public function testAPurposeIsOneToFortyCharacters(string $purpose): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageIsOrContains('A purpose key is 1 to 40 characters.');

        $this->open(hash('sha256', 'token'), purpose: $purpose);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badPurposes(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'one too long' => [str_repeat('p', InboundAddress::MAX_PURPOSE + 1)];
    }

    public function testABlankOwnerIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageIsOrContains('An owner is named by an id.');

        $this->open(hash('sha256', 'token'), owner: '   ');
    }

    public function testALabelIsTrimmedAndCutByCharacters(): void
    {
        $address = $this->open(hash('sha256', 'token'), label: '  Bank feed  ');
        $long = $this->open(hash('sha256', 'token'), label: str_repeat('ř', InboundAddress::MAX_LABEL + 5));

        self::assertSame('Bank feed', $address->label());
        self::assertSame(str_repeat('ř', InboundAddress::MAX_LABEL), $long->label());
    }

    public function testRenamingTrimsTheLabelAndRefusesAnEmptyOrTooLongOne(): void
    {
        $address = $this->open(hash('sha256', 'token'));

        $address->rename('  Receipts  ');
        self::assertSame('Receipts', $address->label());
        $address->rename(str_repeat('ř', InboundAddress::MAX_LABEL));
        self::assertSame(str_repeat('ř', InboundAddress::MAX_LABEL), $address->label());
        foreach (['', '   ', str_repeat('ř', InboundAddress::MAX_LABEL + 1)] as $label) {
            try {
                $address->rename($label);
                self::fail('Not refused.');
            } catch (\DomainException) {
                self::assertSame(str_repeat('ř', InboundAddress::MAX_LABEL), $address->label());
            }
        }
    }

    public function testRevokingTwiceKeepsTheFirstMoment(): void
    {
        $address = $this->open(hash('sha256', 'token'));
        $first = new \DateTimeImmutable('2026-11-03T09:00:00+00:00');

        $address->revoke($first);
        $address->revoke(new \DateTimeImmutable('2026-11-09T09:00:00+00:00'));

        self::assertTrue($address->isRevoked());
        self::assertSame($first, $address->revokedAt());
    }

    private function open(string $hash, string $purpose = 'bank_feed', ?string $owner = null, string $label = 'Bank feed'): InboundAddress
    {
        return new InboundAddress($purpose, $owner, $label, $hash, 'sealed', new \DateTimeImmutable('2026-11-02T09:00:00+01:00'));
    }
}
