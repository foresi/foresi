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

    private static int $failures = 0;

    private static bool $permanent = false;

    public function send(MailMessage $message, FirmMailServer $server): void
    {
        if (\in_array(self::REFUSING_RECIPIENT, [...$message->to, ...$message->cc], true)) {
            throw new MailRefused(true, 'SMTP 550: the mail server refused the message.');
        }
        if (self::$failures > 0) {
            --self::$failures;

            throw self::$permanent ? new MailRefused(true, 'SMTP 550: the mail server refused the message.') : new MailRefused(false, 'SMTP 451: the mail server asks to try again later.');
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

    public function failNext(int $times, bool $permanent): void
    {
        self::$failures = $times;
        self::$permanent = $permanent;
    }

    public static function reset(): void
    {
        self::$messages = [];
        self::$failures = 0;
        self::$permanent = false;
    }
}
