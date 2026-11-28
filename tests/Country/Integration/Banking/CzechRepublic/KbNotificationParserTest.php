<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Country\Integration\Banking\CzechRepublic;

use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Contract\ParsedMovement;
use Foresi\Banking\Contract\ParseFailed;
use Foresi\Banking\Contract\ParseFailure;
use Foresi\Banking\Contract\ParseResult;
use Foresi\Country\Integration\Banking\CzechRepublic\KbNotificationParser;
use Foresi\Mail\Contract\InboundMail;
use Foresi\Mail\Infrastructure\Mime\ZbatesonMailReader;
use Foresi\Shared\Domain\Currency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KbNotificationParserTest extends TestCase
{
    private const string OWN_IBAN = 'CZ6508000000192000145399';
    private const string COUNTER = '123-4567890123/0100';
    private const string COUNTER_IBAN = 'CZ1001000001234567890123';

    public function testItNamesItselfAndTheBanksDomains(): void
    {
        $parser = new KbNotificationParser();

        self::assertSame('cz.kb', $parser->id());
        self::assertSame(2, $parser->version());
        self::assertSame(['kb.cz', 'kbinfo.cz'], $parser->senderDomains());
    }

    public function testIncomingBookedGivesOneIncomingMovement(): void
    {
        $mail = $this->mail('incoming-booked.eml');

        self::assertTrue(new KbNotificationParser()->supports($mail));
        $this->assertMovements([$this->expected()], $this->parse($mail));
    }

    public function testOutgoingBookedGivesOneOutgoingMovement(): void
    {
        $this->assertMovements([$this->expected([
            'direction' => MovementDirection::Outgoing,
            'counterName' => 'Example Supplier Ltd',
            'variableSymbol' => '7700123',
            'constantSymbol' => null,
        ])], $this->parse($this->mail('outgoing-booked.eml')));
    }

    public function testEmptyAndZeroSymbolsAreNull(): void
    {
        $this->assertMovements([$this->expected([
            'variableSymbol' => null,
            'constantSymbol' => null,
            'specificSymbol' => null,
        ])], $this->parse($this->mail('incoming-no-vs.eml')));
    }

    public function testTheMessageKeepsItsDiacritics(): void
    {
        $this->assertMovements([$this->expected([
            'message' => 'Úhrada faktury 2026001, děkujeme – Šťastný Žluťoučký',
        ])], $this->parse($this->mail('incoming-with-message.eml')));
    }

    public function testWindows1250QuotedPrintableIsRead(): void
    {
        $this->assertMovements([$this->expected([
            'counterName' => 'Příkladná Účetní s.r.o.',
            'message' => 'Platba za služby říjen',
        ])], $this->parse($this->mail('incoming-1250-qp.eml')));
    }

    public function testThousandsWithNbspParseExactly(): void
    {
        $movements = $this->parse($this->mail('incoming-thousands.eml'))->movements();

        self::assertCount(1, $movements);
        self::assertSame(1234560, $movements[0]->amount->minor());
        self::assertSame(Currency::of('CZK'), $movements[0]->amount->currency());
    }

    public function testEuroWithDotThousandsAndAnIbanCounterAccount(): void
    {
        $this->assertMovements([$this->expected([
            'amount' => [125000, Currency::of('EUR')],
            'counterAccount' => 'AT611904300234573201',
            'counterIban' => 'AT611904300234573201',
            'constantSymbol' => null,
            'specificSymbol' => null,
        ])], $this->parse($this->mail('incoming-eur.eml')));
    }

    public function testARejectedPaymentIsIgnoredWithItsStatus(): void
    {
        // Rejected is on the closed list of statuses known never to reach the account.
        $result = $this->parse($this->mail('incoming-not-booked.eml'));

        self::assertSame([], $result->movements());
        self::assertSame('status:Zamítnuto', $result->ignoredReason());
    }

    public function testAnUnknownStatusFailsVisibly(): void
    {
        // A status the parser does not know may be a payment that is on the account after all.
        $this->assertFailure(ParseFailure::UnknownLayout, 'Odloženo', $this->mail('incoming-unknown-status.eml'));
    }

    public function testShuffledInlineLayoutStillParses(): void
    {
        // Label: value in divs, fields in another order, an IBAN for the own account,
        // ISO and short dates, and a field the parser does not know.
        $this->assertMovements([$this->expected()], $this->parse($this->mail('layout-divs-inline.eml')));
    }

    public function testEnglishLabelsAloneAreEnough(): void
    {
        $this->assertMovements([$this->expected(['amount' => [9990, Currency::of('CZK')]])], $this->parse($this->mail('english-only.eml')));
    }

    public function testCzechLabelsAloneAreEnough(): void
    {
        $html = $this->htmlOf('incoming-booked.eml');
        $czechOnly = substr($html, 0, (int) strpos($html, '<hr>')).'</body></html>';

        $this->assertMovements([$this->expected()], $this->parse($this->inline(html: $czechOnly)));
    }

    public function testDisagreeingLanguagesAreInconsistent(): void
    {
        $this->assertFailure(ParseFailure::Inconsistent, 'amount', $this->mail('languages-disagree.eml'));
    }

    public function testMissingAmountIsMissingField(): void
    {
        $this->assertFailure(ParseFailure::MissingField, 'amount', $this->mail('missing-amount.eml'));
    }

    public function testTwoPaymentsGiveTwoMovements(): void
    {
        $this->assertMovements([
            $this->expected(),
            $this->expected([
                'amount' => [240000, Currency::of('CZK')],
                'counterAccount' => '9876543210/2010',
                'counterIban' => 'CZ2620100000009876543210',
                'counterName' => 'Second Example Ltd',
                'variableSymbol' => '2026002',
                'constantSymbol' => null,
                'specificSymbol' => '77',
            ]),
        ], $this->parse($this->mail('two-payments.eml')));
    }

    public function testAForeignCounterAccountIsKeptAsWritten(): void
    {
        $this->assertMovements([$this->expected([
            'counterAccount' => '000123456789',
            'counterIban' => null,
            'counterName' => 'Example Overseas Inc',
            'constantSymbol' => null,
            'specificSymbol' => null,
        ])], $this->parse($this->mail('foreign-counteraccount.eml')));
    }

    public function testANonKbSenderIsNotSupported(): void
    {
        self::assertFalse(new KbNotificationParser()->supports($this->mail('not-kb.eml')));
    }

    #[DataProvider('senders')]
    public function testTheSenderDomainMustAlign(string $from, bool $supported): void
    {
        self::assertSame($supported, new KbNotificationParser()->supports($this->inline(html: $this->htmlOf('incoming-booked.eml'), from: $from)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function senders(): iterable
    {
        yield 'kb.cz' => ['info@kb.cz', true];
        yield 'kbinfo.cz' => ['servis@kbinfo.cz', true];
        yield 'a subdomain' => ['info@mail.kb.cz', true];
        yield 'upper case' => ['INFO@KB.CZ', true];
        yield 'a lookalike' => ['info@evilkb.cz', false];
        yield 'a suffix' => ['info@kb.cz.example.com', false];
    }

    public function testAKbMailWithoutAnyLabelIsNotSupported(): void
    {
        self::assertFalse(new KbNotificationParser()->supports($this->inline(html: '<p>Nový produkt pro Vás</p><p>New product for you</p>')));
        self::assertFalse(new KbNotificationParser()->supports($this->inline()));
    }

    public function testAnUnknownHeadingIsAnUnknownLayout(): void
    {
        // Should an incoming notice carry a heading the parser does not know, the
        // fields are still there: it fails visibly instead of being passed over.
        $html = str_replace(['Příchozí platba', 'Incoming payment'], ['Připsaná úhrada', 'Credited payment'], $this->htmlOf('incoming-booked.eml'));
        $mail = $this->inline(html: $html);

        self::assertTrue(new KbNotificationParser()->supports($mail));
        $this->assertFailure(ParseFailure::UnknownLayout, 'heading', $mail);
    }

    public function testLeadingZerosInSymbolsSurvive(): void
    {
        $html = str_replace('2026001', '0012345', $this->htmlOf('incoming-booked.eml'));

        $this->assertMovements([$this->expected(['variableSymbol' => '0012345'])], $this->parse($this->inline(html: $html)));
    }

    public function testANegativeAmountUnderIncomingIsInconsistent(): void
    {
        $html = str_replace('150,00 CZK', '-150,00 CZK', $this->htmlOf('incoming-booked.eml'));

        $this->assertFailure(ParseFailure::Inconsistent, 'amount', $this->inline(html: $html));
    }

    public function testANegativeAmountUnderOutgoingIsItsSize(): void
    {
        $html = str_replace('150,00 CZK', '-150,00 CZK', $this->htmlOf('outgoing-booked.eml'));

        $movements = $this->parse($this->inline(html: $html))->movements();

        self::assertCount(1, $movements);
        self::assertSame(MovementDirection::Outgoing, $movements[0]->direction);
        self::assertSame(15000, $movements[0]->amount->minor());
    }

    #[DataProvider('amounts')]
    public function testCzechNumberFormatting(string $written, int $minor, Currency $currency): void
    {
        $html = str_replace('150,00 CZK', $written, $this->htmlOf('incoming-booked.eml'));

        $movements = $this->parse($this->inline(html: $html))->movements();

        self::assertSame($minor, $movements[0]->amount->minor());
        self::assertSame($currency, $movements[0]->amount->currency());
    }

    /**
     * @return iterable<string, array{string, int, Currency}>
     */
    public static function amounts(): iterable
    {
        yield 'whole' => ['150 CZK', 15000, Currency::of('CZK')];
        yield 'one decimal' => ['150,5 CZK', 15050, Currency::of('CZK')];
        yield 'space thousands' => ['1 234 567,89 CZK', 123456789, Currency::of('CZK')];
        yield 'nbsp thousands' => ["1\u{A0}234,00 CZK", 123400, Currency::of('CZK')];
        yield 'narrow nbsp thousands' => ["1\u{202F}234,00 CZK", 123400, Currency::of('CZK')];
        yield 'dot thousands' => ['1.234.567,00 CZK', 123456700, Currency::of('CZK')];
        yield 'no space before the currency' => ['150,00CZK', 15000, Currency::of('CZK')];
        yield 'a cent' => ['0,01 USD', 1, Currency::of('USD')];
        yield 'crowns written Kč' => ['150,00 Kč', 15000, Currency::of('CZK')];
    }

    #[DataProvider('badAmounts')]
    public function testAnUnreadableAmountIsAnInvalidValue(string $written): void
    {
        $html = str_replace('150,00 CZK', $written, $this->htmlOf('incoming-booked.eml'));

        $this->assertFailure(ParseFailure::InvalidValue, 'amount', $this->inline(html: $html));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badAmounts(): iterable
    {
        yield 'a currency not supported' => ['150,00 XYZ'];
        yield 'no currency' => ['150,00'];
        yield 'a decimal dot' => ['150.00 CZK'];
        yield 'a dot that is no thousands separator' => ['1.50 CZK'];
        yield 'groups not of three' => ['12 34,00 CZK'];
        yield 'three decimals' => ['150,000 CZK'];
        yield 'zero' => ['0,00 CZK'];
        yield 'words' => ['sto padesát CZK'];
        yield 'too large' => ['999 999 999 999 999 999 999,00 CZK'];
    }

    public function testAnOwnAccountThatIsNoAccountIsAnInvalidValue(): void
    {
        $html = str_replace('19-2000145399/0800', 'unknown', $this->htmlOf('incoming-booked.eml'));

        $this->assertFailure(ParseFailure::InvalidValue, 'own account', $this->inline(html: $html));
    }

    public function testAMissingStatusIsAMissingField(): void
    {
        $html = str_replace(['Stav platby', 'Status of the payment'], ['Poznámka', 'Note'], $this->htmlOf('incoming-booked.eml'));

        $this->assertFailure(ParseFailure::MissingField, 'status', $this->inline(html: $html));
    }

    public function testASymbolWithLettersIsAnInvalidValue(): void
    {
        $html = str_replace('2026001', 'INV-2026001', $this->htmlOf('incoming-booked.eml'));

        $this->assertFailure(ParseFailure::InvalidValue, 'variable symbol', $this->inline(html: $html));
    }

    public function testAnImpossibleDateIsAnInvalidValue(): void
    {
        $html = str_replace('02.11.2026', '31.11.2026', $this->htmlOf('incoming-booked.eml'));

        $this->assertFailure(ParseFailure::InvalidValue, 'date', $this->inline(html: $html));
    }

    public function testADateMayBeWrittenMonthFirstWithDashes(): void
    {
        $html = str_replace('02.11.2026', '11-02-2026', $this->htmlOf('incoming-booked.eml'));

        $this->assertMovements([$this->expected()], $this->parse($this->inline(html: $html)));
    }

    public function testAKbPlusIncomingNoticeGivesOneBookedMovement(): void
    {
        // KB+ states no status: the bank says it has received the payment.
        $mail = $this->mail('kb-plus-incoming.eml');

        self::assertTrue(new KbNotificationParser()->supports($mail));
        $this->assertMovements([$this->kbPlusExpected()], $this->parse($mail));
    }

    public function testAKbPlusTextNoticeIsRead(): void
    {
        $this->assertMovements([$this->kbPlusExpected()], $this->parse($this->inline(text: $this->kbPlus(), from: 'servis@kbinfo.cz')));
    }

    public function testAKbPlusNoticeInOneLanguageIsRead(): void
    {
        $english = $this->kbPlus(czech: false);

        $this->assertMovements([$this->kbPlusExpected()], $this->parse($this->inline(text: $english)));
        $this->assertMovements([$this->kbPlusExpected()], $this->parse($this->inline(text: $this->kbPlus(english: false))));
    }

    #[DataProvider('englishAmounts')]
    public function testAKbPlusEnglishAmountIsWrittenTheEnglishWay(string $czech, string $english, int $minor, Currency $currency): void
    {
        $movements = $this->parse($this->inline(text: $this->kbPlus(czechAmount: $czech, englishAmount: $english)))->movements();

        self::assertCount(1, $movements);
        self::assertSame($minor, $movements[0]->amount->minor());
        self::assertSame($currency, $movements[0]->amount->currency());
    }

    /**
     * @return iterable<string, array{string, string, int, Currency}>
     */
    public static function englishAmounts(): iterable
    {
        yield 'thousands' => ['48 176,00 Kč', '48,176.00 CZK', 4817600, Currency::of('CZK')];
        yield 'millions' => ['1 234 567,89 Kč', '1,234,567.89 CZK', 123456789, Currency::of('CZK')];
        yield 'whole' => ['150 Kč', '150 CZK', 15000, Currency::of('CZK')];
        yield 'one decimal' => ['150,5 Kč', '150.5 CZK', 15050, Currency::of('CZK')];
        yield 'no grouping' => ['1234,00 Kč', '1234.00 CZK', 123400, Currency::of('CZK')];
        yield 'euro' => ['1 250,00 EUR', '1,250.00 EUR', 125000, Currency::of('EUR')];
    }

    #[DataProvider('badEnglishAmounts')]
    public function testAKbPlusEnglishAmountWrittenTheCzechWayIsAnInvalidValue(string $english): void
    {
        $this->assertFailure(ParseFailure::InvalidValue, 'amount', $this->inline(text: $this->kbPlus(czech: false, englishAmount: $english)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badEnglishAmounts(): iterable
    {
        yield 'a decimal comma' => ['48 176,00 CZK'];
        yield 'dot thousands' => ['48.176,00 CZK'];
        yield 'groups not of three' => ['4,81,76.00 CZK'];
        yield 'three decimals' => ['48,176.000 CZK'];
        yield 'no currency' => ['48,176.00'];
    }

    public function testKbPlusLanguagesDisagreeingOnTheAmountAreInconsistent(): void
    {
        $this->assertFailure(ParseFailure::Inconsistent, 'amount', $this->inline(text: $this->kbPlus(englishAmount: '48,167.00 CZK')));
    }

    public function testKbPlusLanguagesDisagreeingOnTheDateAreInconsistent(): void
    {
        $this->assertFailure(ParseFailure::Inconsistent, 'date', $this->inline(text: $this->kbPlus(englishDate: '11-21-2026')));
    }

    public function testAKbPlusEnglishDateIsMonthFirst(): void
    {
        // Day first, "20-11-2026" names a 20th month.
        $this->assertFailure(ParseFailure::InvalidValue, 'date', $this->inline(text: $this->kbPlus(czech: false, englishDate: '20-11-2026')));
    }

    public function testAKbPlusNoticeWithoutTheOwnAccountIsAMissingField(): void
    {
        $text = str_replace(["Na účet\n", "To account\n"], ['', ''], $this->kbPlus());

        $this->assertFailure(ParseFailure::MissingField, 'own account', $this->inline(text: $text));
    }

    public function testTheLabelsOfOneLayoutAreNotReadUnderTheHeadingOfTheOther(): void
    {
        // Under a KB+ heading the amount of the heading counts, not a field of the other notice.
        $text = str_replace("Z účtu\n", "Částka a měna: 1 500,00 CZK\nStav platby: Zamítnuto\nZ účtu\n", $this->kbPlus(english: false));
        $this->assertMovements([$this->kbPlusExpected()], $this->parse($this->inline(text: $text)));

        $text = "Incoming payment\nAmount and currency: 150,00 CZK\nTo account: 19-2000145399/0800\nStatus of the payment: Booked\n";
        $this->assertFailure(ParseFailure::MissingField, 'own account', $this->inline(text: $text));
    }

    public function testAFieldAfterTheKbPlusFooterIsAnUnknownLayout(): void
    {
        $text = str_replace("Volat do KB\n", "Volat do KB\nVariabilní symbol: 2026009\n", $this->kbPlus(english: false));

        $this->assertFailure(ParseFailure::UnknownLayout, 'stands under no heading', $this->inline(text: $text));
    }

    public function testAFooterLineBeforeAnyHeadingIsPassedOver(): void
    {
        $this->assertMovements([$this->kbPlusExpected()], $this->parse($this->inline(text: "We are here to help\n".$this->kbPlus())));
    }

    public function testANoticeMixingTheHeadingsOfBothLayoutsIsAnUnknownLayout(): void
    {
        $text = $this->kbPlus(english: false)."Incoming payment\nAmount and currency: 48 176,00 CZK\n"
            ."Your account number: 19-2000145399/0800\nStatus of the payment: Booked\nDue date: 20.11.2026\n";

        $this->assertFailure(ParseFailure::UnknownLayout, 'mixes', $this->inline(text: $text));
    }

    public function testWithoutADueDateTheMailDateCountsInPrague(): void
    {
        $html = str_replace(['Datum splatnosti', 'Due date'], ['Poznámka', 'Note'], $this->htmlOf('incoming-booked.eml'));
        // 23:30 UTC on 1 November is already 2 November in Prague.
        $mail = $this->inline(html: $html, date: new \DateTimeImmutable('2026-11-01T23:30:00+00:00'));

        $movements = $this->parse($mail)->movements();

        self::assertSame('2026-11-02', $movements[0]->bookedOn->format('Y-m-d'));
    }

    public function testADisagreementInTheCountOfPaymentsIsInconsistent(): void
    {
        $html = $this->htmlOf('two-payments.eml');
        $lastEnglish = (int) strrpos($html, '<tr><td colspan="2"');
        $html = substr($html, 0, $lastEnglish).'</table></body></html>';

        $this->assertFailure(ParseFailure::Inconsistent, 'payments', $this->inline(html: $html));
    }

    public function testAFieldTwiceInOneBlockIsAmbiguous(): void
    {
        $html = str_replace(
            '<tr><td style="color:#555">Variable symbol</td><td>2026001</td></tr>',
            '<tr><td style="color:#555">Variable symbol</td><td>2026001</td></tr><tr><td>Variable symbol</td><td>2026009</td></tr>',
            $this->htmlOf('incoming-booked.eml'),
        );

        $this->assertFailure(ParseFailure::Ambiguous, 'variable symbol', $this->inline(html: $html));
    }

    public function testMatchingTextAndHtmlPartsAreBothRead(): void
    {
        $text = "Příchozí platba\nČástka a měna\n150,00 CZK\nČíslo vašeho účtu\n19-2000145399/0800\n"
            ."Číslo protiúčtu\n".self::COUNTER."\nJméno protiúčtu\nExample Buyer Ltd\nDatum splatnosti\n02.11.2026\n"
            ."Stav platby\nZaúčtováno\nVariabilní symbol\n2026001\nKonstantní symbol\n0308\nSpecifický symbol\n0\n";

        $this->assertMovements([$this->expected()], $this->parse($this->inline(text: $text, html: $this->htmlOf('incoming-booked.eml'))));
    }

    public function testDisagreeingTextAndHtmlPartsAreInconsistent(): void
    {
        $text = "Incoming payment\nAmount and currency: 1 500,00 CZK\nYour account number: 19-2000145399/0800\nStatus of the payment: Booked\n";

        $this->assertFailure(ParseFailure::Inconsistent, 'text and HTML', $this->inline(text: $text, html: $this->htmlOf('incoming-booked.eml')));
    }

    public function testAPlainTextNoticeIsRead(): void
    {
        $text = "Incoming payment\nAmount and currency: 150,00 CZK\nYour account number: 19-2000145399/0800\n"
            .'Counteraccount number: '.self::COUNTER."\nCounteraccount name: Example Buyer Ltd\nDue date: 02.11.2026\n"
            ."Status of the payment: Booked\nVariable symbol: 2026001\nConstant symbol: 0308\nSpecific symbol:\n";

        $this->assertMovements([$this->expected()], $this->parse($this->inline(text: $text)));
    }

    public function testLabelsMatchWhateverTheirCase(): void
    {
        $html = str_replace(['Částka a měna', 'Amount and currency'], ['ČÁSTKA A MĚNA:', 'AMOUNT AND CURRENCY:'], $this->htmlOf('incoming-booked.eml'));

        $this->assertMovements([$this->expected()], $this->parse($this->inline(html: $html)));
    }

    public function testAValueRightAfterTheColonIsReadWhole(): void
    {
        $text = "Incoming payment\nAmount and currency:150,00 CZK\nYour account number:19-2000145399/0800\n"
            .'Counteraccount number:'.self::COUNTER."\nCounteraccount name:Example Buyer Ltd\nDue date:02.11.2026\n"
            ."Status of the payment:Booked\nVariable symbol:2026001\nConstant symbol:0308\n";

        $this->assertMovements([$this->expected()], $this->parse($this->inline(text: $text)));
    }

    public function testAnOwnIbanInLowerCaseIsRead(): void
    {
        $text = "Incoming payment\nAmount and currency: 150,00 CZK\nYour account number: cz65 0800 0000 1920 0014 5399\n"
            ."Status of the payment: Booked\nDue date: 02.11.2026\n";

        $movements = $this->parse($this->inline(text: $text))->movements();

        self::assertCount(1, $movements);
        self::assertSame(self::OWN_IBAN, $movements[0]->ownAccount);
    }

    public function testOfPaymentsNoneBookedTheFirstStatusIsTheReason(): void
    {
        $payment = "Amount and currency: 150,00 CZK\nYour account number: 19-2000145399/0800\nDue date: 02.11.2026\n";
        $text = "Incoming payment\n".$payment."Status of the payment: Rejected\n"
            ."Incoming payment\n".$payment."Status of the payment: REJECTED\n";

        $result = $this->parse($this->inline(text: $text));

        self::assertSame([], $result->movements());
        self::assertSame('status:Rejected', $result->ignoredReason());
    }

    public function testAFieldAboveTheFirstHeadingIsAnUnknownLayout(): void
    {
        $text = "Amount and currency: 150,00 CZK\nIncoming payment\nAmount and currency: 150,00 CZK\n"
            ."Your account number: 19-2000145399/0800\nStatus of the payment: Booked\nDue date: 02.11.2026\n";

        $this->assertFailure(ParseFailure::UnknownLayout, 'stands under no heading', $this->inline(text: $text));
    }

    public function testALineWithAColonButNoLabelIsNoField(): void
    {
        self::assertFalse(new KbNotificationParser()->supports($this->inline(text: "Dear client: thank you for banking with us.\n")));

        $text = "Dear client: here is your notice.\nIncoming payment\nAmount and currency: 150,00 CZK\n"
            ."Your account number: 19-2000145399/0800\nNote: paid in full\nStatus of the payment: Booked\nDue date: 02.11.2026\n";

        self::assertCount(1, $this->parse($this->inline(text: $text))->movements());
    }

    public function testAFieldOnlyOneLanguageStatesStillCounts(): void
    {
        $text = "Příchozí platba\nČástka a měna: 150,00 CZK\nČíslo vašeho účtu: 19-2000145399/0800\n"
            ."Stav platby: Zaúčtováno\nDatum splatnosti: 02.11.2026\nZpráva pro příjemce: Děkujeme\n"
            ."Incoming payment\nAmount and currency: 150,00 CZK\nYour account number: 19-2000145399/0800\n"
            ."Status of the payment: Booked\nDue date: 02.11.2026\nVariable symbol: 2026001\n";

        $movements = $this->parse($this->inline(text: $text))->movements();

        self::assertCount(1, $movements);
        self::assertSame('Děkujeme', $movements[0]->message);
        self::assertSame('2026001', $movements[0]->variableSymbol);
    }

    public function testACounterAccountWrittenDomesticallyAndAsAnIbanAgrees(): void
    {
        $movements = $this->parse($this->inline(text: $this->bothLanguages(self::COUNTER, 'CZ10 0100 0001 2345 6789 0123')))->movements();

        self::assertCount(1, $movements);
        self::assertSame(self::COUNTER, $movements[0]->counterAccount);
        self::assertSame(self::COUNTER_IBAN, $movements[0]->counterIban);
        self::assertSame('2026001', $movements[0]->variableSymbol);
    }

    public function testTheFieldsAfterACounterAccountWrittenTwoWaysMustStillAgree(): void
    {
        $text = $this->bothLanguages(self::COUNTER, 'CZ10 0100 0001 2345 6789 0123', englishSymbol: '2026009');

        $this->assertFailure(ParseFailure::Inconsistent, 'variable symbol', $this->inline(text: $text));
    }

    public function testTwoForeignCounterAccountsWithoutAnIbanMustBeTheSame(): void
    {
        $this->assertFailure(ParseFailure::Inconsistent, 'counter-account', $this->inline(text: $this->bothLanguages('000123456789', '000123456788')));
    }

    public function testWithoutADueDateAndAMailDateThePaymentHasNoDate(): void
    {
        $text = "Incoming payment\nAmount and currency: 150,00 CZK\nYour account number: 19-2000145399/0800\n"
            ."Status of the payment: Booked\n";

        $this->assertFailure(ParseFailure::MissingField, 'no date', $this->inline(text: $text, date: null));
    }

    private function kbPlus(
        bool $czech = true,
        bool $english = true,
        string $czechAmount = '48 176,00 Kč',
        string $englishAmount = '48,176.00 CZK',
        string $englishDate = '11-20-2026',
    ): string {
        $text = "Podívejte se na podrobnosti o přijaté platbě.\nFor English scroll down\n";
        if ($czech) {
            $text .= "Přijali jsme platbu {$czechAmount} na Váš účet\nSplatnost: 20. 11. 2026\nZ účtu\n\n".self::COUNTER."\n"
                ."Na účet\n19-2000145399/0800\nZpráva pro příjemce\nInvoice 2026001\nVariabilní symbol\n2026001\n"
                ."Konstantní symbol\n308\nSpecifický symbol\nChcete s něčím poradit?\nVolat do KB\n+420 000 000 000\n";
        }
        if ($english) {
            $text .= "We have received the payment {$englishAmount} to your account\nDue date: {$englishDate}\nFrom account\n\n".self::COUNTER."\n"
                ."To account\n19-2000145399/0800\nMessage for beneficiary\nInvoice 2026001\nVariable symbol\n2026001\n"
                ."Constant symbol\n308\nSpecific symbol\nWe are here to help\nCall KB\n+420 000 000 000\n";
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function kbPlusExpected(): array
    {
        return $this->expected([
            'amount' => [4817600, Currency::of('CZK')],
            'counterName' => null,
            'bookedOn' => '2026-11-20',
            'constantSymbol' => '308',
            'message' => 'Invoice 2026001',
        ]);
    }

    private function bothLanguages(string $czechCounter, string $englishCounter, string $englishSymbol = '2026001'): string
    {
        return "Příchozí platba\nČástka a měna: 150,00 CZK\nČíslo vašeho účtu: 19-2000145399/0800\n"
            ."Číslo protiúčtu: {$czechCounter}\nStav platby: Zaúčtováno\nDatum splatnosti: 02.11.2026\nVariabilní symbol: 2026001\n"
            ."Incoming payment\nAmount and currency: 150,00 CZK\nYour account number: 19-2000145399/0800\n"
            ."Counteraccount number: {$englishCounter}\nStatus of the payment: Booked\nDue date: 02.11.2026\nVariable symbol: {$englishSymbol}\n";
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function expected(array $overrides = []): array
    {
        return array_merge([
            'direction' => MovementDirection::Incoming,
            'amount' => [15000, Currency::of('CZK')],
            'ownAccount' => self::OWN_IBAN,
            'counterAccount' => self::COUNTER,
            'counterIban' => self::COUNTER_IBAN,
            'counterName' => 'Example Buyer Ltd',
            'bookedOn' => '2026-11-02',
            'variableSymbol' => '2026001',
            'constantSymbol' => '0308',
            'specificSymbol' => null,
            'message' => null,
            'bankReference' => null,
        ], $overrides);
    }

    /**
     * @param list<array<string, mixed>> $expected
     */
    private function assertMovements(array $expected, ParseResult $result): void
    {
        self::assertNull($result->ignoredReason());
        self::assertSame($expected, array_map($this->actual(...), $result->movements()));
        foreach ($result->movements() as $movement) {
            self::assertSame('Europe/Prague', $movement->bookedOn->getTimezone()->getName());
            self::assertSame('00:00:00', $movement->bookedOn->format('H:i:s'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function actual(ParsedMovement $movement): array
    {
        return [
            'direction' => $movement->direction,
            'amount' => [$movement->amount->minor(), $movement->amount->currency()],
            'ownAccount' => $movement->ownAccount,
            'counterAccount' => $movement->counterAccount,
            'counterIban' => $movement->counterIban,
            'counterName' => $movement->counterName,
            'bookedOn' => $movement->bookedOn->format('Y-m-d'),
            'variableSymbol' => $movement->variableSymbol,
            'constantSymbol' => $movement->constantSymbol,
            'specificSymbol' => $movement->specificSymbol,
            'message' => $movement->message,
            'bankReference' => $movement->bankReference,
        ];
    }

    private function assertFailure(ParseFailure $reason, string $detail, InboundMail $mail): void
    {
        try {
            $result = new KbNotificationParser()->parse($mail);
        } catch (ParseFailed $failed) {
            self::assertSame($reason, $failed->reason, $failed->detail);
            self::assertStringContainsStringIgnoringCase($detail, $failed->detail);

            return;
        }

        self::fail(\sprintf('Expected %s, got %d movement(s).', $reason->name, \count($result->movements())));
    }

    private function parse(InboundMail $mail): ParseResult
    {
        return new KbNotificationParser()->parse($mail);
    }

    private function mail(string $fixture): InboundMail
    {
        $raw = file_get_contents(__DIR__.'/../../../../Fixtures/Banking/kb/'.$fixture);
        self::assertIsString($raw);

        return new ZbatesonMailReader()->read($raw);
    }

    private function htmlOf(string $fixture): string
    {
        $html = $this->mail($fixture)->html();
        self::assertIsString($html);

        return $html;
    }

    private function inline(
        ?string $text = null,
        ?string $html = null,
        string $from = 'info@kb.cz',
        ?\DateTimeImmutable $date = new \DateTimeImmutable('2026-11-02T09:15:00+01:00'),
    ): InboundMail {
        return new InboundMail(
            headers: [['From', $from]],
            from: $from,
            subject: 'Oznámení o platbách',
            messageId: 'inline@kb.example.com',
            date: $date,
            text: $text,
            html: $html,
            attachmentNames: ['Oznameni.pdf'],
            sha256: hash('sha256', $html.$text),
            size: \strlen($html.$text),
        );
    }
}
