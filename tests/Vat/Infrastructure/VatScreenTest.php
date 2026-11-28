<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Infrastructure;

use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Vat\Application\CalculationStep;
use Foresi\Vat\Application\PaymentView;
use Foresi\Vat\Application\PeriodAmounts;
use Foresi\Vat\Application\PeriodDetail;
use Foresi\Vat\Application\PeriodList;
use Foresi\Vat\Application\PeriodState;
use Foresi\Vat\Application\PeriodView;
use Foresi\Vat\Application\ScheduleAmount;
use Foresi\Vat\Application\SettledMark;
use Foresi\Vat\Application\VatPart;
use Foresi\Vat\Contract\DocumentVat;
use Foresi\Vat\Contract\PossibleDuplicate;
use Foresi\Vat\Contract\ScheduledVat;
use Foresi\Vat\Contract\VatPayment;
use Foresi\Vat\Contract\VatPeriod;
use Foresi\Vat\Contract\VatSubject;
use Foresi\Vat\Infrastructure\Screen\VatScreen;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * What the VAT page shows of the periods: each state in a neutral or brand
 * badge and never red or overdue, the amounts and dates as the locale
 * writes them, the warnings, the footer and one period in the sheet.
 */
final class VatScreenTest extends KernelTestCase
{
    private VatScreen $screen;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->screen = self::getContainer()->get(VatScreen::class);
        $this->locale('cs');
    }

    public function testNoStateRendersRedOrOverdue(): void
    {
        foreach (['cs', 'en'] as $locale) {
            $this->locale($locale);
            foreach (PeriodState::cases() as $state) {
                foreach ([189_000, -300_000, 0] as $minor) {
                    $row = $this->screen->row($this->view(output: max($minor, 0), input: max(-$minor, 0), state: $state));

                    self::assertNotSame('red', $row['tone'], $state->value);
                    self::assertContains($row['tone'], ['outline', 'gray', 'green'], $state->value);
                    foreach ($row as $text) {
                        self::assertDoesNotMatchRegularExpression('/overdue|po splatnosti/i', (string) $text, $state->value);
                    }
                }
            }
        }
    }

    public function testEachStateHasItsBadge(): void
    {
        $badges = [];
        foreach (PeriodState::cases() as $state) {
            $badges[$state->value] = $this->screen->badge($this->view(state: $state));
        }

        self::assertSame([
            'estimated' => ['Odhad', 'outline'],
            'return_entered' => ['Částka z přiznání', 'gray'],
            'settled' => ['Uhrazeno', 'green'],
            'not_yet_paid' => ['Zatím nezaplaceno', 'gray'],
        ], $badges);
        self::assertSame(['Zatím nepřišlo', 'gray'], $this->screen->badge($this->view(output: 0, input: 300_000, state: PeriodState::NotYetPaid)));
        self::assertSame(['Zatím nezaplaceno', 'gray'], $this->screen->badge($this->view(output: 0, input: 0, state: PeriodState::NotYetPaid)));
    }

    public function testARowShowsThePeriodItsAmountsAndWhenItIsDue(): void
    {
        $row = $this->screen->row($this->view());

        self::assertSame([
            'key' => '2026-11',
            'label' => 'listopad 2026',
            'output' => "2\u{a0}100\u{a0}Kč",
            'input' => "210\u{a0}Kč",
            'result' => "1\u{a0}890\u{a0}Kč",
            'resultLabel' => 'K zaplacení',
            'due' => '28. 12. 2026',
            'state' => 'estimated',
            'stateLabel' => 'Odhad',
            'tone' => 'outline',
            'href' => '/vat/periods/2026-11',
        ], $row);

        $refund = $this->screen->row($this->view(output: 0, input: 300_050));
        self::assertSame(["3\u{a0}000,50\u{a0}Kč", 'Nadměrný odpočet', '27. 1. 2027'], [$refund['result'], $refund['resultLabel'], $refund['due']]);

        $zero = $this->screen->row($this->view(output: 0, input: 0));
        self::assertSame(["0\u{a0}Kč", 'Nic k zaplacení', null], [$zero['result'], $zero['resultLabel'], $zero['due']]);

        $entered = $this->screen->row($this->view(state: PeriodState::ReturnEntered, returnAmount: Money::ofMinor(150_000, Currency::of('CZK'))));
        self::assertSame(["1\u{a0}500\u{a0}Kč", 'Částka z přiznání'], [$entered['result'], $entered['stateLabel']]);

        $this->locale('en');
        self::assertSame(['November 2026', 'To pay', 'Dec 28, 2026'], [$this->screen->row($this->view())['label'], $this->screen->row($this->view())['resultLabel'], $this->screen->row($this->view())['due']]);
    }

    public function testAQuarterIsNamedByItsNumber(): void
    {
        $quarter = new VatPeriod('2027-Q2', $this->day('2027-04-01'), $this->day('2027-06-30'), VatPeriodLength::Quarter);

        self::assertSame('2. čtvrtletí 2027', $this->screen->periodLabel($quarter));
        $this->locale('en');
        self::assertSame('Q2 2027', $this->screen->periodLabel($quarter));
        self::assertSame('Q4 2027', $this->screen->periodLabel(new VatPeriod('2027-Q4', $this->day('2027-10-01'), $this->day('2027-12-31'), VatPeriodLength::Quarter)));
    }

    public function testTheTabsAreLastThisAndNextYear(): void
    {
        self::assertSame([
            ['label' => '2025', 'href' => '/vat?year=2025', 'active' => false],
            ['label' => '2026', 'href' => '/vat?year=2026', 'active' => true],
            ['label' => '2027', 'href' => '/vat?year=2027', 'active' => false],
        ], $this->screen->tabs(2026, 2026));
    }

    public function testTheWarningsNameTheirPeriodsAndTheSources(): void
    {
        $missing = $this->view(warnings: [PeriodAmounts::WARNING_MISSING_CZK_VAT]);
        $december = $this->view(key: '2026-12', start: '2026-12-01', end: '2026-12-31', warnings: [PeriodAmounts::WARNING_MISSING_CZK_VAT, 'something_new']);
        $list = $this->list([$missing, $december], warnings: ['quarter_not_allowed', 'unknown_status'], unavailable: ['received-documents.received_vat', 'elsewhere.vat']);

        self::assertSame([
            ['key' => 'quarter_not_allowed', 'text' => 'Firma platí DPH čtvrtletně, ale nový plátce podává v prvním roce měsíčně (§ 99a ZDPH). Zkontroluj délku období v DPH firmy.'],
            ['key' => 'unknown_status', 'text' => 'unknown_status'],
            ['key' => 'missing_czk_vat', 'text' => 'Některé doklady nemají DPH v korunách a počítají se nulou: listopad 2026, prosinec 2026.'],
            ['key' => 'something_new', 'text' => 'something_new'],
            ['key' => 'unavailable_sources', 'text' => 'Některé zdroje se nepodařilo načíst, čísla proto nemusí být úplná: přijaté doklady, elsewhere.vat.'],
        ], $this->screen->warnings($list));
        self::assertSame([], $this->screen->warnings($this->list([$this->view()])));
    }

    public function testTheDuplicatesAndTheUnassignedPayments(): void
    {
        $payment = new PaymentView(new VatPayment('m1', $this->day('2026-12-27'), Money::ofMinor(150_000, Currency::of('CZK')), false, VatPayment::OTHER), null, false);
        $refund = new PaymentView(new VatPayment('m2', $this->day('2027-01-20'), Money::ofMinor(300_000, Currency::of('CZK')), true, VatPayment::REFUND), null, true);
        $list = $this->list([], duplicates: [new PossibleDuplicate('p1', 'VAT November', '/cash-flow/planned-costs/p1'), new PossibleDuplicate('p2', 'VAT')], unassigned: [$payment, $refund]);

        self::assertSame([['name' => 'VAT November', 'href' => '/cash-flow/planned-costs/p1'], ['name' => 'VAT', 'href' => null]], $this->screen->duplicates($list));
        self::assertSame([
            ['id' => 'm1', 'on' => '27. 12. 2026', 'amount' => "1\u{a0}500\u{a0}Kč", 'direction' => 'Zaplaceno', 'rule' => 'Jiný variabilní symbol', 'manual' => false],
            ['id' => 'm2', 'on' => '20. 1. 2027', 'amount' => "+3\u{a0}000\u{a0}Kč", 'direction' => 'Přišlo', 'rule' => 'Účet finančního úřadu', 'manual' => true],
        ], $this->screen->unassigned($list));
    }

    public function testTheFooterTranslatesWhatIsNotVerifiedOrCounted(): void
    {
        $list = $this->list([], unverified: ['refund_timing', 'not_known_yet'], gaps: ['plans_not_counted', 'other_gap']);

        self::assertSame([
            'unverified' => ['kdy finanční úřad vrátí nadměrný odpočet (§ 105 ZDPH)', 'not_known_yet'],
            'gaps' => ['plánované položky', 'other_gap'],
        ], $this->screen->footer($list));
    }

    public function testTheSheetShowsTheBreakdownTheDocumentsTheStepsAndThePayments(): void
    {
        $paid = new PaymentView(new VatPayment('m1', $this->day('2026-12-27'), Money::ofMinor(150_000, Currency::of('CZK')), false, VatPayment::PAYABLE), '2026-11', true);
        $view = $this->view(state: PeriodState::Settled, payments: [$paid], mark: new SettledMark($this->day('2026-12-28'), 'u1', null), missingCzk: ['FV-7']);
        $document = new DocumentVat('invoicing.issued_vat', new VatSubject('invoice', 'i1', '/invoices/i1'), 'FV-1', $this->day('2026-11-20'), $this->day('2026-11-20'), Money::ofMinor(210_000, Currency::of('CZK')), Money::ofMinor(0, Currency::of('CZK')), true);
        $schedule = new ScheduleAmount(new ScheduledVat('invoicing.recurring_vat', new VatSubject('recurring_invoice', 'r1', null), 'Hosting', $this->day('2026-11-30'), Money::ofMinor(10_000, Currency::of('EUR'))), Money::ofMinor(25_000, Currency::of('CZK')), true, false);

        $detail = $this->screen->detail(new PeriodDetail($this->list([$view]), $view, [$document], ['received-documents.received_vat'], [$schedule]));

        self::assertSame('listopad 2026', $detail['title']);
        self::assertSame('12. 11. 2026 – 30. 11. 2026', $detail['span']);
        self::assertSame('Uhrazeno', $detail['row']['stateLabel']);
        self::assertSame([
            ['key' => 'documents', 'label' => 'Doklady', 'output' => "2\u{a0}100\u{a0}Kč", 'input' => "210\u{a0}Kč", 'empty' => null],
            ['key' => 'schedules', 'label' => 'Plány opakování', 'output' => "0\u{a0}Kč", 'input' => "0\u{a0}Kč", 'empty' => null],
            ['key' => 'plans', 'label' => 'Plány', 'output' => "0\u{a0}Kč", 'input' => "0\u{a0}Kč", 'empty' => null],
        ], $detail['parts']);
        self::assertSame([['label' => 'FV-1', 'href' => '/invoices/i1', 'on' => '20. 11. 2026', 'output' => "2\u{a0}100\u{a0}Kč", 'input' => "0\u{a0}Kč", 'missingCzk' => true]], $detail['documents']);
        self::assertSame([['label' => 'Hosting', 'href' => null, 'on' => '30. 11. 2026', 'output' => "250\u{a0}Kč", 'fxToday' => true, 'missingCzk' => false]], $detail['schedules']);
        self::assertTrue($detail['restricted']);
        self::assertSame([
            ['name' => 'output', 'label' => 'DPH na výstupu', 'rule' => 'DPH vydaných dokladů a opakovaných faktur s DUZP v období.', 'amount' => "2\u{a0}100\u{a0}Kč"],
            ['name' => 'input', 'label' => 'DPH na vstupu', 'rule' => 'Odpočet DPH z přijatých dokladů v období.', 'amount' => "210\u{a0}Kč"],
            ['name' => 'result', 'label' => 'Výsledek', 'rule' => 'DPH na výstupu minus DPH na vstupu; kladný k zaplacení, záporný je nadměrný odpočet.', 'amount' => "1\u{a0}890\u{a0}Kč"],
        ], $detail['steps']);
        self::assertSame([['id' => 'm1', 'on' => '27. 12. 2026', 'amount' => "1\u{a0}500\u{a0}Kč", 'direction' => 'Zaplaceno', 'rule' => 'Účet a variabilní symbol', 'manual' => true]], $detail['payments']);
        self::assertSame("Zaplaceno 1\u{a0}500\u{a0}Kč, očekáváno 1\u{a0}890\u{a0}Kč, rozdíl \u{2212}390\u{a0}Kč. Období je uhrazené, rozdíl je pro informaci.", $detail['difference']);
        self::assertNull($detail['returnAmount']);
        self::assertSame("1\u{a0}890\u{a0}Kč", $detail['estimate']);
        self::assertTrue($detail['settledMark']);
        self::assertTrue($detail['settled']);
        self::assertSame(['FV-7'], $detail['missingCzk']);

        $open = $this->screen->detail(new PeriodDetail($this->list([$this->view()], gaps: ['schedules_not_counted', 'plans_not_counted']), $this->view(returnAmount: Money::ofMinor(150_000, Currency::of('CZK'))), [], [], []));
        self::assertSame([null, 'Zatím se nepočítá', 'Zatím se nepočítá'], array_column($open['parts'], 'empty'));
        self::assertFalse($open['restricted']);
        self::assertFalse($open['settledMark']);
        self::assertFalse($open['settled']);
        self::assertNull($open['difference']);
        self::assertSame("1\u{a0}500\u{a0}Kč", $open['returnAmount']);
    }

    public function testTheDifferenceIsPlainInformation(): void
    {
        $payment = static fn (int $minor): PaymentView => new PaymentView(new VatPayment('m'.$minor, new \DateTimeImmutable('2026-12-27'), Money::ofMinor($minor, Currency::of('CZK')), false, VatPayment::PAYABLE), '2026-11', false);

        self::assertNull($this->screen->difference($this->view()));
        self::assertSame("Zaplaceno 1\u{a0}890\u{a0}Kč, jak se čekalo.", $this->screen->difference($this->view(payments: [$payment(189_000)])));
        self::assertSame("Zaplaceno 2\u{a0}000\u{a0}Kč, očekáváno 1\u{a0}890\u{a0}Kč, rozdíl +110\u{a0}Kč. Období je uhrazené, rozdíl je pro informaci.", $this->screen->difference($this->view(payments: [$payment(200_000)])));
    }

    public function testThePeriodChoicesAreNewestFirst(): void
    {
        $list = $this->list([$this->view(), $this->view(key: '2026-12', start: '2026-12-01', end: '2026-12-31')]);

        self::assertSame(['2026-12' => 'prosinec 2026', '2026-11' => 'listopad 2026'], $this->screen->periodChoices($list));
    }

    private function locale(string $locale): void
    {
        self::getContainer()->get(LocaleSwitcher::class)->setLocale($locale);
    }

    /**
     * A November 2026 period from the 12th: 2,100 output and 210 input VAT, due on 28 December.
     *
     * @param list<string>      $warnings
     * @param list<PaymentView> $payments
     * @param list<string>      $missingCzk
     */
    private function view(
        string $key = '2026-11',
        string $start = '2026-11-12',
        string $end = '2026-11-30',
        int $output = 210_000,
        int $input = 21_000,
        PeriodState $state = PeriodState::Estimated,
        ?Money $returnAmount = null,
        array $warnings = [],
        array $payments = [],
        ?SettledMark $mark = null,
        array $missingCzk = [],
    ): PeriodView {
        $period = new VatPeriod($key, $this->day($start), $this->day($end), VatPeriodLength::Month);
        $czk = static fn (int $minor): Money => Money::ofMinor($minor, Currency::of('CZK'));
        $result = $czk($output - $input);
        $amounts = new PeriodAmounts($period, new VatPart($czk($output), $czk($input)), VatPart::zero(), VatPart::zero(), [
            new CalculationStep(CalculationStep::OUTPUT, $czk($output), 'output', []),
            new CalculationStep(CalculationStep::INPUT, $czk($input), 'input', []),
            new CalculationStep(CalculationStep::RESULT, $result, 'result', ['output', 'input']),
        ], [], $warnings, $missingCzk);

        return new PeriodView($period, $this->day('2026-12-28'), $this->day('2027-01-27'), $amounts, $returnAmount, $state, $payments, $mark);
    }

    /**
     * @param list<PeriodView>        $periods
     * @param list<string>            $warnings
     * @param list<string>            $unavailable
     * @param list<string>            $unverified
     * @param list<string>            $gaps
     * @param list<PossibleDuplicate> $duplicates
     * @param list<PaymentView>       $unassigned
     */
    private function list(array $periods, array $warnings = [], array $unavailable = [], array $unverified = [], array $gaps = PeriodList::KNOWN_GAPS, array $duplicates = [], array $unassigned = []): PeriodList
    {
        return new PeriodList(null, 'CZ', '2026-01', '2026-12', $periods, $warnings, $unverified, $unavailable, $gaps, $unassigned, $duplicates);
    }

    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('Europe/Prague'));
    }
}
