<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Infrastructure;

use Foresi\Mail\Infrastructure\LegacyMailSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

/**
 * MAIL_INBOUND_* replace BANK_INBOX_*; for one more release the old name is read
 * when the new one is empty, and a deprecation warning names it, never its value.
 */
final class LegacyMailSettingsTest extends TestCase
{
    /** @var list<array{string, array<mixed>}> */
    private array $warnings = [];

    public function testTheOldBankInboxSettingStillWorksWithADeprecationWarning(): void
    {
        $settings = $this->settings();
        $env = ['BANK_INBOX_HOST' => 'imap.example.com', 'MAIL_INBOUND_HOST' => ''];

        self::assertSame('imap.example.com', $settings->getEnv('mail_inbound', 'HOST', $this->lookup($env)));

        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('deprecated', $this->warnings[0][0]);
        self::assertSame('BANK_INBOX_HOST', $this->warnings[0][1]['old']);
        self::assertSame('MAIL_INBOUND_HOST', $this->warnings[0][1]['new']);
        self::assertStringNotContainsString('imap.example.com', json_encode($this->warnings, \JSON_THROW_ON_ERROR));
    }

    public function testTheWarningComesOncePerOldNameAndProcess(): void
    {
        $settings = $this->settings();
        $env = ['BANK_INBOX_HOST' => 'imap.example.com', 'BANK_INBOX_USER' => 'mail@example.com'];

        $settings->getEnv('mail_inbound', 'HOST', $this->lookup($env));
        $settings->getEnv('mail_inbound', 'HOST', $this->lookup($env));
        $settings->getEnv('mail_inbound', 'USER', $this->lookup($env));

        self::assertCount(2, $this->warnings);
    }

    public function testTheNewNameWinsWhenBothAreSetAndNothingIsLogged(): void
    {
        $env = ['MAIL_INBOUND_HOST' => 'new.example.com', 'BANK_INBOX_HOST' => 'old.example.com'];

        self::assertSame('new.example.com', $this->settings()->getEnv('mail_inbound', 'HOST', $this->lookup($env)));
        self::assertSame([], $this->warnings);
    }

    public function testASettingSetUnderNeitherNameIsEmptyOrHasItsDefault(): void
    {
        $settings = $this->settings();
        $lookup = $this->lookup([]);

        self::assertSame('', $settings->getEnv('mail_inbound', 'HOST', $lookup));
        self::assertSame('', $settings->getEnv('mail_inbound', 'PASSWORD', $lookup));
        self::assertSame('993', $settings->getEnv('mail_inbound', 'PORT', $lookup));
        self::assertSame('ssl', $settings->getEnv('mail_inbound', 'ENCRYPTION', $lookup));
        self::assertSame('INBOX', $settings->getEnv('mail_inbound', 'FOLDER', $lookup));
        self::assertSame([], $this->warnings);
    }

    public function testAnOldValueBeatsADefault(): void
    {
        $env = ['MAIL_INBOUND_PORT' => '', 'BANK_INBOX_PORT' => '143'];

        self::assertSame('143', $this->settings()->getEnv('mail_inbound', 'PORT', $this->lookup($env)));
    }

    private function settings(): LegacyMailSettings
    {
        $logger = new class($this) extends AbstractLogger {
            public function __construct(private readonly LegacyMailSettingsTest $test)
            {
            }

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                if ('warning' === $level) {
                    $this->test->warn((string) $message, $context);
                }
            }
        };

        return new LegacyMailSettings($logger);
    }

    /**
     * @param array<mixed> $context
     */
    public function warn(string $message, array $context): void
    {
        $this->warnings[] = [$message, $context];
    }

    /**
     * @param array<string, string> $env
     */
    private function lookup(array $env): \Closure
    {
        return static fn (string $name): string => $env[$name] ?? throw new EnvNotFoundException($name);
    }
}
