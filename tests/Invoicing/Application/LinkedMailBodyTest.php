<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Application;

use App\Invoicing\Application\LinkedMailBody;
use PHPUnit\Framework\TestCase;

final class LinkedMailBodyTest extends TestCase
{
    private const string URL = 'https://invoices.example.com/d/abc?x=1&y=2';

    public function testTheLinkGoesWhereTheBodyPlacesIt(): void
    {
        $body = new LinkedMailBody("Dear customer,\nthe invoice: {link}\n\nRegards", self::URL);

        self::assertSame("Dear customer,\nthe invoice: ".self::URL."\n\nRegards", $body->text());
        self::assertSame(
            "<p>Dear customer,<br>\nthe invoice: <a href=\"https://invoices.example.com/d/abc?x=1&amp;y=2\">https://invoices.example.com/d/abc?x=1&amp;y=2</a></p>\n<p>Regards</p>",
            $body->html(),
        );
    }

    public function testWithoutAPlaceTheLinkEndsTheMailOnAParagraphOfItsOwn(): void
    {
        $body = new LinkedMailBody("Dear customer \n\n", self::URL);

        self::assertSame("Dear customer\n\n".self::URL, $body->text());
        self::assertSame(
            "<p>Dear customer</p>\n<p><a href=\"https://invoices.example.com/d/abc?x=1&amp;y=2\">https://invoices.example.com/d/abc?x=1&amp;y=2</a></p>",
            $body->html(),
        );
    }

    public function testBlankLinesAroundTheBodyMakeNoEmptyParagraphs(): void
    {
        $body = new LinkedMailBody("\n\nPay by {link} please.\n\n\n", self::URL);

        self::assertSame('<p>Pay by <a href="https://invoices.example.com/d/abc?x=1&amp;y=2">https://invoices.example.com/d/abc?x=1&amp;y=2</a> please.</p>', $body->html());
    }

    public function testEverythingTheWriterTypedIsEscaped(): void
    {
        $body = new LinkedMailBody("<b>Tom's \"firm\"</b> & co {link}", 'https://invoices.example.com/d/"x\'');

        self::assertSame(
            '<p>&lt;b&gt;Tom&apos;s &quot;firm&quot;&lt;/b&gt; &amp; co <a href="https://invoices.example.com/d/&quot;x&apos;">https://invoices.example.com/d/&quot;x&apos;</a></p>',
            $body->html(),
        );
    }
}
