<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application;

use Foresi\Invoicing\Application\LinkedMailBody;
use Foresi\Invoicing\Application\MailText;
use Foresi\Shared\Infrastructure\Markdown\CommonMarkMarkdown;
use PHPUnit\Framework\TestCase;

final class LinkedMailBodyTest extends TestCase
{
    private const string URL = 'https://invoices.example.com/d/abc?x=1&y=2';
    private const string ANCHOR = '<a href="https://invoices.example.com/d/abc?x&#61;1&amp;y&#61;2" rel="noopener noreferrer nofollow">https://invoices.example.com/d/abc?x&#61;1&amp;y&#61;2</a>';

    public function testTheLinkGoesWhereTheBodyPlacesIt(): void
    {
        $body = $this->body("Dear customer,\nthe invoice: {link}\n\nRegards");

        self::assertSame("Dear customer,\nthe invoice: ".self::URL."\n\nRegards", $body->text());
        self::assertSame("<p>Dear customer,<br />\nthe invoice: ".self::ANCHOR."</p>\n<p>Regards</p>", $body->html());
    }

    public function testWithoutAPlaceTheLinkEndsTheMailOnAParagraphOfItsOwn(): void
    {
        $body = $this->body("Dear customer \n\n");

        self::assertSame("Dear customer\n\n".self::URL, $body->text());
        self::assertSame("<p>Dear customer</p>\n<p>".self::ANCHOR.'</p>', $body->html());
    }

    public function testBlankLinesAroundTheBodyMakeNoEmptyParagraphs(): void
    {
        self::assertSame('<p>Pay by '.self::ANCHOR.' please.</p>', $this->body("\n\nPay by {link} please.\n\n\n")->html());
    }

    public function testTheLinkCanBeTheTargetOfAMarkdownLink(): void
    {
        $body = $this->body('[Open the invoice]({link})');

        self::assertSame('<p><a href="https://invoices.example.com/d/abc?x&#61;1&amp;y&#61;2" rel="noopener noreferrer nofollow">Open the invoice</a></p>', $body->html());
        self::assertSame('[Open the invoice]('.self::URL.')', $body->text());
    }

    public function testTheBodyIsMarkdown(): void
    {
        $html = $this->body("Pay **by Friday**, _please_:\n\n- online\n- by bank transfer\n\n{link}")->html();

        self::assertStringContainsString('<strong>by Friday</strong>, <em>please</em>', $html);
        self::assertStringContainsString("<ul>\n<li>online</li>\n<li>by bank transfer</li>\n</ul>", $html);
    }

    public function testRawHtmlAndImagesNeverReachTheMail(): void
    {
        $html = $this->body("<b>Tom's</b> <script>x()</script> ![logo](https://example.com/logo.png) [bad](javascript:alert(1)) {link}")->html();

        self::assertStringContainsString('&lt;b&gt;Tom', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testAValueFilledInIsTextNotMarkup(): void
    {
        $source = new MailText()->renderBody("Dear {customer_name},\n{description}\n\n{link}", [
            'customer_name' => '[Pay here](https://evil.example.com) **Ltd** <b>',
            'description' => "# Not a heading\n1. not a list",
        ]);
        $body = $this->body($source);
        $html = $body->html();

        self::assertStringNotContainsString('evil.example.com"', $html);
        self::assertStringNotContainsString('<strong>', $html);
        self::assertStringNotContainsString('<h3>', $html);
        self::assertStringNotContainsString('<ol>', $html);
        self::assertStringContainsString('Dear [Pay here](https://evil.example.com) **Ltd** &lt;b&gt;,', $html);
        self::assertStringContainsString("Dear [Pay here](https://evil.example.com) **Ltd** <b>,\n# Not a heading\n1. not a list", $body->text());
    }

    public function testAValueInsideASentenceKeepsItsMarks(): void
    {
        $source = new MailText()->renderBody('Total {amount_total} due {due_date}.', ['amount_total' => '-1 210,00 Kč', 'due_date' => '1. 12. 2026']);

        self::assertSame('Total -1 210,00 Kč due 1. 12. 2026.', $source);
    }

    public function testAValueThatStartsALineCannotStartABlock(): void
    {
        $source = new MailText()->renderBody("{due_date} is the day.\n  {amount_total}\n\n{link}", ['due_date' => '1. 12. 2026', 'amount_total' => '- 5 Kč']);

        self::assertSame("1\\. 12. 2026 is the day.\n  \\- 5 Kč\n\n{link}", $source);
        self::assertStringStartsWith("<p>1. 12. 2026 is the day.<br />\n- 5 Kč</p>", $this->body($source)->html());
        self::assertStringStartsWith("1. 12. 2026 is the day.\n  - 5 Kč", $this->body($source)->text());
    }

    public function testABareAddressInAValueStaysText(): void
    {
        $source = new MailText()->renderBody('Dear {customer_name}, {link}', ['customer_name' => 'www.evil.example.com and https://evil.example.com']);

        self::assertStringNotContainsString('evil.example.com"', $this->body($source)->html());
        self::assertStringStartsWith('Dear www.evil.example.com and https://evil.example.com, ', $this->body($source)->text());
    }

    public function testThePlainTemplatesOfBeforeReadAsBefore(): void
    {
        $html = $this->body("Na základě Vaší objednávky Vám zasílám fakturu č. 20260001 na částku 12 100,00 Kč se splatností 1. 12. 2026.\n\nDěkuji,\nJana Nováková\n\n{link}")->html();

        self::assertSame("<p>Na základě Vaší objednávky Vám zasílám fakturu č. 20260001 na částku 12 100,00 Kč se splatností 1. 12. 2026.</p>\n<p>Děkuji,<br />\nJana Nováková</p>\n<p>".self::ANCHOR.'</p>', $html);
    }

    private function body(string $source): LinkedMailBody
    {
        return new LinkedMailBody($source, self::URL, new CommonMarkMarkdown());
    }
}
