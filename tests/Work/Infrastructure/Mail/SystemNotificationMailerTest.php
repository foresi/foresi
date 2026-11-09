<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Work\Infrastructure\Mail;

use App\Work\Infrastructure\Mail\SystemNotificationMailer;
use PHPUnit\Framework\TestCase;

final class SystemNotificationMailerTest extends TestCase
{
    public function testTheExcerptIsTheTextOnOneLine(): void
    {
        self::assertSame('Hello <world> & you. Next item', SystemNotificationMailer::excerpt("<p>Hello &lt;world&gt; &amp; <strong>you</strong>.</p>\n<ul><li>Next</li><li>item</li></ul>"));
        self::assertSame('One two', SystemNotificationMailer::excerpt('<p>One<br>two</p>'));
    }

    public function testALongTextIsCutAtAWordNearTheLimit(): void
    {
        $text = str_repeat('word ', 100);
        $excerpt = SystemNotificationMailer::excerpt('<p>'.$text.'</p>');

        self::assertSame(str_repeat('word ', 55).'word…', $excerpt);
        self::assertSame(SystemNotificationMailer::EXCERPT, mb_strlen($excerpt));
    }

    public function testATextOfOneLongWordIsCutAtTheLimit(): void
    {
        $excerpt = SystemNotificationMailer::excerpt(str_repeat('ž', 300));

        self::assertSame(str_repeat('ž', 280).'…', $excerpt);
    }

    public function testATextAtTheLimitIsWhole(): void
    {
        $text = str_repeat('a', 280);

        self::assertSame($text, SystemNotificationMailer::excerpt($text));
    }

    public function testACutSentenceLosesItsTrailingPunctuation(): void
    {
        $excerpt = SystemNotificationMailer::excerpt(str_repeat('a', 200).', '.str_repeat('b', 100));

        self::assertSame(str_repeat('a', 200).'…', $excerpt);
    }
}
