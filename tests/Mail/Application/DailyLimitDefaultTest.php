<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Application;

use Foresi\Mail\Application\DailyLimit;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An installation whose own .env has no MAIL_INBOUND_DAILY_LIMIT still boots,
 * with 500 mails per address and day.
 */
final class DailyLimitDefaultTest extends KernelTestCase
{
    private ?string $server = null;
    private ?string $env = null;
    private string|false $process = false;

    protected function setUp(): void
    {
        $this->server = \is_string($_SERVER['MAIL_INBOUND_DAILY_LIMIT'] ?? null) ? $_SERVER['MAIL_INBOUND_DAILY_LIMIT'] : null;
        $this->env = \is_string($_ENV['MAIL_INBOUND_DAILY_LIMIT'] ?? null) ? $_ENV['MAIL_INBOUND_DAILY_LIMIT'] : null;
        $this->process = getenv('MAIL_INBOUND_DAILY_LIMIT');
        unset($_SERVER['MAIL_INBOUND_DAILY_LIMIT'], $_ENV['MAIL_INBOUND_DAILY_LIMIT']);
        putenv('MAIL_INBOUND_DAILY_LIMIT');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (null !== $this->server) {
            $_SERVER['MAIL_INBOUND_DAILY_LIMIT'] = $this->server;
        }
        if (null !== $this->env) {
            $_ENV['MAIL_INBOUND_DAILY_LIMIT'] = $this->env;
        }
        if (false !== $this->process) {
            putenv('MAIL_INBOUND_DAILY_LIMIT='.$this->process);
        }
    }

    public function testAMissingSettingTakesTheDefault(): void
    {
        self::bootKernel();

        self::assertSame(500, self::getContainer()->get(DailyLimit::class)->limit());
    }
}
