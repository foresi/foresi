<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Integration\Purchasing;

use Foresi\Margin\Application\MappingView;
use Foresi\Margin\Application\SetLineAllocationInput;
use Foresi\Margin\Application\TermView;
use Foresi\Margin\Integration\Purchasing\LineForm;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * What the line dialog shows of a stored mapping, what it reads of a post and what it makes of it, in the
 * locale of the person.
 */
final class LineFormTest extends TestCase
{
    private const string CLIENT = '0197a1de-0000-7000-8000-00000000000a';

    public function testAStoredMappingFillsTheRowsInTheLocaleOfThePerson(): void
    {
        $mapping = new MappingView('fixed', [
            new TermView(self::CLIENT, 'Example Customer a.s.', null, Money::ofMinor(123_456, Currency::CZK), false),
            new TermView('own', null, null, null, true),
        ]);

        $czech = $this->form('cs')->fromMapping($mapping);
        $english = $this->form('en')->fromMapping($mapping);

        self::assertSame('fixed', $czech['kind']);
        self::assertSame(['client', self::CLIENT, 'Example Customer a.s.', '', '1234,56', false], $this->row($czech['rows'][0]));
        self::assertSame(['own', '', '', '', '', true], $this->row($czech['rows'][1]));
        self::assertSame('1234.56', $english['rows'][0]['amount']);
        self::assertCount(LineForm::ROWS, $czech['rows']);
    }

    public function testAPercentIsWrittenWithTheDecimalSeparatorOfTheLocale(): void
    {
        $mapping = new MappingView('percent', [new TermView('own', null, '33.33', null, false), new TermView(self::CLIENT, 'Example Customer a.s.', '66.67', null, false)]);

        self::assertSame(['33,33', '66,67'], [$this->form('cs')->fromMapping($mapping)['rows'][0]['percent'], $this->form('cs')->fromMapping($mapping)['rows'][1]['percent']]);
        self::assertSame('33.33', $this->form('en')->fromMapping($mapping)['rows'][0]['percent']);
    }

    public function testNoMappingIsAWholeAllocationWithEmptyRows(): void
    {
        $form = $this->form('en')->fromMapping(null);

        self::assertSame('whole', $form['kind']);
        self::assertCount(LineForm::ROWS, $form['rows']);
        self::assertSame(['', '', '', '', '', false], $this->row($form['rows'][0]));
    }

    public function testAPostIsReadRowByRowWithAtMostTenRows(): void
    {
        $shares = [];
        for ($index = 0; $index < 12; ++$index) {
            $shares[] = ['type' => 'own', 'percent' => (string) $index];
        }
        $shares[1] = ['type' => 'client', 'contactId' => self::CLIENT, 'query' => 'Example', 'amount' => '5', 'rest' => '1'];

        $form = $this->form('en')->fromRequest(Request::create('/', 'POST', ['kind' => 'fixed', 'shares' => $shares])->request);

        self::assertSame('fixed', $form['kind']);
        self::assertCount(10, $form['rows']);
        self::assertSame(['client', self::CLIENT, 'Example', '', '5', true], $this->row($form['rows'][1]));
        self::assertSame(['own', '', '', '9', '', false], $this->row($form['rows'][9]));
    }

    public function testARowWithoutARecipientTypeMustBeEmptyAndIsSkipped(): void
    {
        $blank = ['type' => '', 'contactId' => '', 'query' => '', 'percent' => '', 'amount' => '', 'rest' => false];
        $own = ['type' => 'own', 'contactId' => '', 'query' => '', 'percent' => '', 'amount' => '', 'rest' => false];
        $form = $this->form('en');

        $input = $form->input('doc', 'rate:21', $this->values('whole', [$blank, $own]));
        self::assertInstanceOf(SetLineAllocationInput::class, $input);
        self::assertSame([['recipient' => 'own']], $input->shares, 'The row after the empty one is still read.');

        $input = $form->input('doc', 'rate:21', $this->values('whole', [[...$blank, 'percent' => '  ', 'amount' => ' '], $own]));
        self::assertInstanceOf(SetLineAllocationInput::class, $input, 'Spaces alone are no entry.');

        foreach ([['percent' => '50'], ['amount' => '50'], ['contactId' => self::CLIENT]] as $filled) {
            self::assertSame('margin.screen.error.recipient', $form->input('doc', 'rate:21', $this->values('whole', [[...$blank, ...$filled], $own])));
        }
    }

    public function testAClientRowWithoutAContactIsToldSo(): void
    {
        $values = ['kind' => 'whole', 'rows' => [['type' => 'client', 'contactId' => '', 'query' => 'Exa', 'percent' => '', 'amount' => '', 'rest' => false]]];

        self::assertSame('margin.screen.error.client', $this->form('en')->input('doc', 'rate:21', $values));
    }

    public function testPercentsAreReadInTheLocaleAndABlankPercentIsLeftOut(): void
    {
        $values = ['kind' => 'percent', 'rows' => [
            ['type' => 'own', 'contactId' => '', 'query' => '', 'percent' => ' 33,33 ', 'amount' => '', 'rest' => false],
            ['type' => 'client', 'contactId' => self::CLIENT, 'query' => '', 'percent' => '  ', 'amount' => '', 'rest' => false],
        ]];

        $input = $this->form('cs')->input('doc', 'rate:21', $values);

        self::assertInstanceOf(SetLineAllocationInput::class, $input);
        self::assertSame([['recipient' => 'own', 'percent' => '33.33'], ['recipient' => self::CLIENT, 'percent' => null]], $input->shares);
    }

    public function testFixedAmountsRestAndBlankAmountsAreRead(): void
    {
        $values = ['kind' => 'fixed', 'rows' => [
            ['type' => 'own', 'contactId' => '', 'query' => '', 'percent' => '', 'amount' => '1 234,50', 'rest' => false],
            ['type' => 'client', 'contactId' => self::CLIENT, 'query' => '', 'percent' => '', 'amount' => '   ', 'rest' => false],
            ['type' => 'client', 'contactId' => '0197a1de-0000-7000-8000-00000000000b', 'query' => '', 'percent' => '', 'amount' => '', 'rest' => true],
        ]];

        $input = $this->form('cs')->input('doc', 'rate:21', $values);

        self::assertInstanceOf(SetLineAllocationInput::class, $input);
        self::assertSame([
            ['recipient' => 'own', 'amount' => '1234.50'],
            ['recipient' => self::CLIENT, 'amount' => null],
            ['recipient' => '0197a1de-0000-7000-8000-00000000000b', 'rest' => true],
        ], $input->shares);
        self::assertSame('margin.screen.error.amount', $this->form('en')->input('doc', 'rate:21', ['kind' => 'fixed', 'rows' => [['type' => 'own', 'contactId' => '', 'query' => '', 'percent' => '', 'amount' => 'abc', 'rest' => false]]]));
    }

    /**
     * @param list<array{type: string, contactId: string, query: string, percent: string, amount: string, rest: bool}> $rows
     *
     * @return array{kind: string, rows: list<array{type: string, contactId: string, query: string, percent: string, amount: string, rest: bool}>}
     */
    private function values(string $kind, array $rows): array
    {
        return ['kind' => $kind, 'rows' => $rows];
    }

    private function form(string $locale): LineForm
    {
        $switcher = new LocaleSwitcher($locale, []);

        return new LineForm($switcher);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<mixed>
     */
    private function row(array $row): array
    {
        return [$row['type'], $row['contactId'], $row['query'], $row['percent'], $row['amount'], $row['rest']];
    }
}
