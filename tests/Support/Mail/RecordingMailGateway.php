<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Support\Mail;

use App\Invoicing\Application\MailGateway;
use App\Invoicing\Application\MailMessage;
use App\Invoicing\Application\MailRefused;
use App\Invoicing\Domain\FirmMailServer;

/**
 * The mail gateway of the test and end-to-end environments: it keeps what it is
 * given and sends nothing. It can be told to fail, and a message to
 * REFUSING_RECIPIENT always fails permanently, so a browser test can show a
 * failed send. The record is static, as the container owns the instance.
 */
final class RecordingMailGateway implements MailGateway
{
    public const string REFUSING_RECIPIENT = 'refuse@example.com';

    /** @var list<MailMessage> */
    private static array $messages = [];
    /** @var list<MailMessage> */
    private static array $attempts = [];
    /** @var (\Closure(): void)|null */
    private static ?\Closure $whileSending = null;
    private static int $delayMicroseconds = 0;

    private static int $failures = 0;

    private static bool $permanent = false;
    private static bool $settingsProblem = false;

    public function send(MailMessage $message, FirmMailServer $server): void
    {
        self::$attempts[] = $message;
        if (self::$delayMicroseconds > 0) {
            usleep(self::$delayMicroseconds);
        }
        if (self::$whileSending instanceof \Closure) {
            $during = self::$whileSending;
            self::$whileSending = null;
            $during();
        }
        if (\in_array(self::REFUSING_RECIPIENT, [...$message->to, ...$message->cc], true)) {
            throw new MailRefused(true, 'SMTP 550: the mail server refused the message.', reasonCode: MailRefused::MESSAGE_REFUSED, smtpCode: 550);
        }
        if (self::$failures > 0) {
            --self::$failures;

            throw match (true) {
                self::$settingsProblem => new MailRefused(self::$permanent, 'SMTP 535: the mail server refused the login.', true, MailRefused::LOGIN_REFUSED, 535),
                self::$permanent => new MailRefused(true, 'SMTP 550: the mail server refused the message.', reasonCode: MailRefused::MESSAGE_REFUSED, smtpCode: 550),
                default => new MailRefused(false, 'SMTP 451: the mail server asks to try again later.', reasonCode: MailRefused::TRY_LATER, smtpCode: 451),
            };
        }

        self::$messages[] = $message;
    }

    /**
     * @return list<MailMessage> what was accepted, oldest first
     */
    public function messages(): array
    {
        return self::$messages;
    }

    /**
     * @return list<MailMessage> every message handed over, accepted or not, oldest first
     */
    public function attempts(): array
    {
        return self::$attempts;
    }

    /**
     * Runs once inside the next send, as if something happened while the server talked.
     *
     * @param \Closure(): void $during
     */
    public static function whileSending(\Closure $during): void
    {
        self::$whileSending = $during;
    }

    /**
     * Makes every send take this long, so parallel workers overlap.
     */
    public static function slowDown(int $microseconds): void
    {
        self::$delayMicroseconds = $microseconds;
    }

    /**
     * @param bool $settingsProblem a refused login, which the firm fixes in its settings
     */
    public function failNext(int $times, bool $permanent, bool $settingsProblem = false): void
    {
        self::$failures = $times;
        self::$permanent = $permanent;
        self::$settingsProblem = $settingsProblem;
    }

    /**
     * The next message fails as a refused login: a settings problem.
     */
    public function failNextAsSettingsProblem(): void
    {
        $this->failNext(1, true, true);
    }

    public static function reset(): void
    {
        self::$messages = [];
        self::$attempts = [];
        self::$whileSending = null;
        self::$delayMicroseconds = 0;
        self::$failures = 0;
        self::$permanent = false;
        self::$settingsProblem = false;
    }
}
