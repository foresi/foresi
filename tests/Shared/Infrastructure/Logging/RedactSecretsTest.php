<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Logging;

use App\Shared\Infrastructure\Logging\RedactSecrets;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * No secret or personal value reaches a log line or an operator mail: sensitive
 * keys are blanked at any depth, and public document links, query strings and
 * e-mail addresses are cut out of every text.
 */
final class RedactSecretsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveKeys(): iterable
    {
        foreach (['password', 'plainPassword', 'smtp_passwd', 'secret', 'clientSecret', 'token', 'csrf_token', 'Authorization', 'cookie', 'iban', 'accountIban', 'email', 'recipientEmail', 'body', 'api_key', 'apiKey'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('sensitiveKeys')]
    public function testASensitiveKeyIsBlanked(string $key): void
    {
        $record = $this->process([$key => 'value-1', 'invoice' => 'FV-1']);

        self::assertSame('[redacted]', $record->context[$key]);
        self::assertSame('FV-1', $record->context['invoice']);
    }

    public function testNestedContextAndExtraAreRedacted(): void
    {
        $record = (new RedactSecrets())(new LogRecord(
            new \DateTimeImmutable(),
            'app',
            Level::Error,
            'Failed',
            ['firm' => ['smtp' => ['host' => 'mail.example.com', 'password' => 'x'], 'list' => [['token' => 't']]], 'count' => 3],
            ['request' => ['headers' => ['authorization' => 'Bearer x']]],
        ));

        self::assertSame(['firm' => ['smtp' => ['host' => 'mail.example.com', 'password' => '[redacted]'], 'list' => [['token' => '[redacted]']]], 'count' => 3], $record->context);
        self::assertSame(['headers' => ['authorization' => '[redacted]']], $record->extra['request']);
    }

    public function testThePublicLinkTokenIsCutFromTextAtAnyDepth(): void
    {
        $token = str_repeat('Ab3_', 10).'xyz';
        $record = (new RedactSecrets())(new LogRecord(
            new \DateTimeImmutable(),
            'request',
            Level::Error,
            'Uncaught exception at https://foresi.example.com/d/'.$token.'/pdf',
            ['request_uri' => 'https://foresi.example.com/d/'.$token, 'route' => ['path' => '/d/'.$token.'/open']],
        ));

        self::assertSame('Uncaught exception at https://foresi.example.com/d/[redacted]/pdf', $record->message);
        self::assertSame('https://foresi.example.com/d/[redacted]', $record->context['request_uri']);
        self::assertSame(['path' => '/d/[redacted]/open'], $record->context['route']);
        self::assertStringNotContainsString($token, json_encode([$record->message, $record->context], \JSON_THROW_ON_ERROR));
    }

    public function testQueryStringsAndEmailAddressesAreCutFromText(): void
    {
        $record = $this->process(['url' => '/reset?token=abc&user=7', 'note' => 'Bounce from jan.novak@example.com.', 'question' => 'Is it paid?']);

        self::assertSame('/reset?[redacted]', $record->context['url']);
        self::assertSame('Bounce from [email].', $record->context['note']);
        self::assertSame('Is it paid?', $record->context['question']);
    }

    public function testOtherValuesAreKept(): void
    {
        $exception = new \RuntimeException('boom');
        $record = $this->process(['exception' => $exception, 'amount' => 12.5, 'paid' => false, 'none' => null, 'route' => 'invoice_show']);

        self::assertSame($exception, $record->context['exception']);
        self::assertSame(['amount' => 12.5, 'none' => null, 'paid' => false, 'route' => 'invoice_show'], $this->sorted(array_diff_key($record->context, ['exception' => true])));
    }

    public function testTextScrubbingIsAvailableOnItsOwn(): void
    {
        self::assertSame('Mail to [email] for /d/[redacted]', RedactSecrets::scrub('Mail to a@example.com for /d/'.str_repeat('A', 43)));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function process(array $context): LogRecord
    {
        return (new RedactSecrets())(new LogRecord(new \DateTimeImmutable(), 'app', Level::Error, 'Failed', $context));
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }
}
