<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Domain;

use App\Party\Domain\RegistryCheck;
use App\Party\Domain\RegistryResult;
use App\Party\Domain\RegistrySource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RegistryCheckTest extends TestCase
{
    #[DataProvider('possible')]
    public function testStoresAResultOfTheMatchingRegistry(RegistrySource $source, RegistryResult $result): void
    {
        $at = new \DateTimeImmutable('2026-10-30 10:00:00');

        $check = new RegistryCheck($source, $result, $at);

        self::assertSame($source, $check->source);
        self::assertSame($result, $check->result);
        self::assertSame($at, $check->checkedAt);
    }

    /**
     * @return iterable<string, array{RegistrySource, RegistryResult}>
     */
    public static function possible(): iterable
    {
        yield 'vies valid' => [RegistrySource::Vies, RegistryResult::Valid];
        yield 'vies invalid' => [RegistrySource::Vies, RegistryResult::Invalid];
        yield 'vies unavailable' => [RegistrySource::Vies, RegistryResult::Unavailable];
        yield 'payer reliable' => [RegistrySource::UnreliablePayer, RegistryResult::Reliable];
        yield 'payer unreliable' => [RegistrySource::UnreliablePayer, RegistryResult::Unreliable];
        yield 'payer not registered' => [RegistrySource::UnreliablePayer, RegistryResult::NotRegistered];
        yield 'payer unavailable' => [RegistrySource::UnreliablePayer, RegistryResult::Unavailable];
    }

    #[DataProvider('impossible')]
    public function testRejectsAResultTheRegistryCannotGive(RegistrySource $source, RegistryResult $result): void
    {
        $this->expectException(\DomainException::class);

        new RegistryCheck($source, $result, new \DateTimeImmutable());
    }

    /**
     * @return iterable<string, array{RegistrySource, RegistryResult}>
     */
    public static function impossible(): iterable
    {
        yield 'vies reliable' => [RegistrySource::Vies, RegistryResult::Reliable];
        yield 'vies unreliable' => [RegistrySource::Vies, RegistryResult::Unreliable];
        yield 'vies not registered' => [RegistrySource::Vies, RegistryResult::NotRegistered];
        yield 'payer valid' => [RegistrySource::UnreliablePayer, RegistryResult::Valid];
        yield 'payer invalid' => [RegistrySource::UnreliablePayer, RegistryResult::Invalid];
    }
}
