<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\NotificationLines;
use PHPUnit\Framework\TestCase;

final class NotificationLinesTest extends TestCase
{
    public function testTableCellsAndRowsBecomeLines(): void
    {
        $html = '<table><tr><td>Amount and currency</td><td><b>150,00</b> CZK</td></tr>'
            .'<tr><th>Variable symbol</th><td>2026001</td></tr></table>';

        self::assertSame(
            ['Amount and currency', '150,00 CZK', 'Variable symbol', '2026001'],
            NotificationLines::of($html, true),
        );
    }

    public function testBlockElementsAndBreaksSplitLines(): void
    {
        $html = '<html><head><title>Notice</title><style>p { color: red; }</style></head>'
            .'<body><h2>Incoming payment</h2><p>First<br>Second<br/>Third</p><div>Fourth</div>'
            .'<ul><li>Fifth</li></ul><script>var x = 1;</script></body></html>';

        self::assertSame(
            ['Incoming payment', 'First', 'Second', 'Third', 'Fourth', 'Fifth'],
            NotificationLines::of($html, true),
        );
    }

    public function testInlineElementsDoNotSplitALine(): void
    {
        self::assertSame(
            ['Amount and currency: 150,00 CZK'],
            NotificationLines::of('<p><span>Amount</span> and <i>currency</i>: <b>150,00</b> CZK</p>', true),
        );
    }

    public function testNoBreakSpacesBecomeSpacesAndWhiteSpaceCollapses(): void
    {
        $html = "<p>12\u{A0}345,60\u{202F}CZK</p><p>  a \t\n b  </p><p>&nbsp;</p><p>1&nbsp;000,00 CZK</p>";

        self::assertSame(['12 345,60 CZK', 'a b', '1 000,00 CZK'], NotificationLines::of($html, true));
    }

    public function testUtf8TextSurvivesWhateverTheDocumentDeclares(): void
    {
        $html = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1250"></head>'
            .'<body><p>Příchozí platba</p><p>Zpráva pro příjemce</p><p>Žluťoučký kůň</p></body></html>';

        self::assertSame(
            ['Příchozí platba', 'Zpráva pro příjemce', 'Žluťoučký kůň'],
            NotificationLines::of($html, true),
        );
    }

    public function testEntitiesAreDecoded(): void
    {
        self::assertSame(['A & B <c>'], NotificationLines::of('<p>A &amp; B &lt;c&gt;</p>', true));
    }

    public function testPlainTextIsSplitOnAnyLineBreak(): void
    {
        $text = "Incoming payment\r\n\r\nAmount and currency\r\n12\u{A0}345,60  CZK\rVariable symbol\n  2026001  \n";

        self::assertSame(
            ['Incoming payment', 'Amount and currency', '12 345,60 CZK', 'Variable symbol', '2026001'],
            NotificationLines::of($text, false),
        );
    }

    public function testPlainTextKeepsMarkupAsWritten(): void
    {
        self::assertSame(['<b>not html</b>'], NotificationLines::of('<b>not html</b>', false));
    }

    public function testEmptyInputGivesNoLines(): void
    {
        self::assertSame([], NotificationLines::of('', true));
        self::assertSame([], NotificationLines::of(" \n\t", false));
        self::assertSame([], NotificationLines::of('<html><body></body></html>', true));
    }
}
