<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Infrastructure;

use Foresi\Mail\Application\TrustSettings;
use Foresi\Mail\Domain\InboundChannel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The settings reach the services through the container, under both names.
 */
final class LegacyMailSettingsWiringTest extends KernelTestCase
{
    /** @var array<string, string|null> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            if (null === $value) {
                unset($_SERVER[$name], $_ENV[$name]);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
            }
        }
        parent::tearDown();
    }

    public function testTheTrustedServersOfTheOldNameAreReadWhenTheNewIsEmpty(): void
    {
        $this->set('MAIL_INBOUND_TRUSTED_AUTHSERV', '');
        $this->set('BANK_INBOX_TRUSTED_AUTHSERV', 'old.example.com');

        $trust = self::getContainer()->get(TrustSettings::class);

        self::assertTrue($trust->trustsAuthserv('old.example.com', InboundChannel::Imap));
        self::assertFalse($trust->trustsAuthserv('mx.example.com', InboundChannel::Imap));
    }

    public function testTheNewNameWinsInTheContainer(): void
    {
        self::bootKernel();
        $this->set('BANK_INBOX_TRUSTED_AUTHSERV', 'old.example.com');

        $trust = self::getContainer()->get(TrustSettings::class);

        self::assertTrue($trust->trustsAuthserv('mx.example.com', InboundChannel::Imap));
        self::assertFalse($trust->trustsAuthserv('old.example.com', InboundChannel::Imap));
    }

    private function set(string $name, string $value): void
    {
        $this->saved[$name] ??= \is_string($_SERVER[$name] ?? null) ? $_SERVER[$name] : null;
        $_SERVER[$name] = $_ENV[$name] = $value;
        self::ensureKernelShutdown();
        self::bootKernel();
    }
}
