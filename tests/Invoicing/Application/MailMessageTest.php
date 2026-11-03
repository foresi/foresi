<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\MailMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MailMessageTest extends TestCase
{
    public function testHoldsTheMessage(): void
    {
        $message = new MailMessage('invoices@example.com', 'Example Firm', 'office@example.com', ['customer@example.com'], ['copy@example.com'], 'Invoice 1', "Line one\r\nLine two", '<p>x</p>', '<1@example.com>');

        self::assertSame('invoices@example.com', $message->from);
        self::assertSame('Example Firm', $message->fromName);
        self::assertSame('office@example.com', $message->replyTo);
        self::assertSame(['customer@example.com'], $message->to);
        self::assertSame(['copy@example.com'], $message->cc);
        self::assertSame('Invoice 1', $message->subject);
        self::assertSame("Line one\r\nLine two", $message->text, 'A body may break lines.');
        self::assertSame('<p>x</p>', $message->html);
        self::assertSame('<1@example.com>', $message->messageId);
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('broken')]
    public function testAHeaderBreakInAnyHeaderIsRefused(array $values): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MailMessage(
            $this->text($values, 'from', 'invoices@example.com'),
            $this->text($values, 'fromName', 'Example Firm'),
            \array_key_exists('replyTo', $values) ? $this->text($values, 'replyTo', '') : null,
            $this->list($values, 'to', ['customer@example.com']),
            $this->list($values, 'cc', []),
            $this->text($values, 'subject', 'Subject'),
            'Text',
            '<p>Text</p>',
            $this->text($values, 'messageId', '<1@example.com>'),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function broken(): iterable
    {
        yield 'from' => [['from' => "invoices@example.com\r\nBcc: x@example.com"]];
        yield 'from name' => [['fromName' => "Example\r\nBcc: x@example.com"]];
        yield 'reply-to' => [['replyTo' => "office@example.com\nBcc: x@example.com"]];
        yield 'to' => [['to' => ["customer@example.com\r\nBcc: x@example.com"]]];
        yield 'a later to' => [['to' => ['customer@example.com', "other@example.com\r\nBcc: x@example.com"]]];
        yield 'cc' => [['cc' => ["copy@example.com\rBcc: x@example.com"]]];
        yield 'subject' => [['subject' => "Invoice\r\nBcc: x@example.com"]];
        yield 'message id' => [['messageId' => "<1@example.com>\r\nBcc: x@example.com"]];
        yield 'nobody to send to' => [['to' => []]];
        yield 'a message id without brackets' => [['messageId' => '1@example.com']];
        yield 'a message id with more after it' => [['messageId' => '<1@example.com> <2@example.com>']];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function text(array $values, string $key, string $default): string
    {
        $value = $values[$key] ?? $default;

        return \is_string($value) ? $value : throw new \LogicException('A text value.');
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $default
     *
     * @return list<string>
     */
    private function list(array $values, string $key, array $default): array
    {
        $value = $values[$key] ?? $default;
        if (!\is_array($value)) {
            throw new \LogicException('A list value.');
        }

        return array_values(array_map(static fn (mixed $item): string => \is_string($item) ? $item : throw new \LogicException('A text item.'), $value));
    }
}
