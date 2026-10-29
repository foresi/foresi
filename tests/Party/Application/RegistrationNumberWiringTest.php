<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application;

use App\Party\Application\RegistrationNumberFactory;
use App\Party\Domain\InvalidRegistrationNumber;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RegistrationNumberWiringTest extends KernelTestCase
{
    public function testContainerFactoryAcceptsValidCzechNumber(): void
    {
        self::assertSame('12345679', $this->factory()->create('CZ', '1234 5679')->value);
    }

    public function testContainerFactoryRejectsBadCzechChecksum(): void
    {
        $this->expectException(InvalidRegistrationNumber::class);

        $this->factory()->create('CZ', '25596642');
    }

    private function factory(): RegistrationNumberFactory
    {
        return self::getContainer()->get('test.registration_number_factory');
    }
}
