<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Vat\Integration\CashFlow;

use Foresi\Identity\Domain\Role;
use Foresi\Party\Contract\VatPeriodLength;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Party\Domain\VatStatusPeriod;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Foresi\Vat\Application\VatPeriods;
use Foresi\Vat\Domain\VatSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The page Money → VAT with a payer from 12 November 2026, on 5 January 2027: November 2026
 * gives 1,890 to pay, due on 28 December and not yet paid, never overdue;
 * December gives 3,000 back. The sheet of a period, the return amount, the
 * settled mark, a payment's period and the payment details are small forms;
 * a guest is refused.
 */
final class VatPageTest extends WebTestCase
{
    use BuildsMartinsVat;

    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2027-01-05 10:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAMemberSeesThePeriodsOfAYearNeverOverdue(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/vat?year=2026');

        self::assertResponseIsSuccessful();
        self::assertSame('2026', trim($crawler->filter('[data-vat-years] a[aria-current="page"]')->text()));
        self::assertSame(['2026-11', '2026-12'], $crawler->filter('[data-vat-period]')->each(static fn (Crawler $row): string => (string) $row->attr('data-vat-period')));
        $november = $this->text($crawler, '[data-vat-period="2026-11"]');
        self::assertStringContainsString('November 2026', $november);
        self::assertStringContainsString('To pay 1,890', $november);
        self::assertStringContainsString('Dec 28, 2026', $november);
        self::assertSame('Not yet paid', $this->text($crawler, '[data-vat-period="2026-11"] [data-vat-state]'));
        self::assertSame('not_yet_paid', $crawler->filter('[data-vat-period="2026-11"]')->attr('data-state'));
        self::assertStringContainsString('Refund 3,000', $this->text($crawler, '[data-vat-period="2026-12"]'));
        self::assertSame('Estimate', $this->text($crawler, '[data-vat-period="2026-12"] [data-vat-state]'));
        self::assertSame('/vat/periods/2026-11', $crawler->filter('[data-vat-period="2026-11"] a[data-sheet]')->attr('href'));
        self::assertStringContainsString('An estimate, not a basis for the VAT return.', $this->text($crawler, '[data-vat-footer]'));
        self::assertStringContainsString('when the tax office pays a refund', $this->text($crawler, '[data-vat-unverified]'));
        self::assertStringContainsString('planned items', $this->text($crawler, '[data-vat-gaps]'));
        self::assertCount(0, $crawler->filter('[data-vat-unassigned]'));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsStringIgnoringCase('overdue', $html);
        self::assertCount(0, $crawler->filter('[data-vat-periods] .bg-badge-red'));
        self::assertSame('/vat', $crawler->filter('#sidebar a[href="/vat"]')->attr('href'));

        $crawler = $this->client->request('GET', '/vat');
        self::assertSame('2027', trim($crawler->filter('[data-vat-years] a[aria-current="page"]')->text()));
        self::assertCount(12, $crawler->filter('[data-vat-period]'));

        $crawler = $this->client->request('GET', '/vat?year=2025');
        self::assertSame('No VAT periods in 2025', $this->text($crawler, '[data-vat-empty="empty"] h2, [data-vat-empty="empty"] h3, [data-vat-empty="empty"] p'));
    }

    public function testAPaymentSettlesThePeriodAndTheDifferenceIsInformation(): void
    {
        $this->paid('m1', '2026-12-27', 1_500);
        $this->signedIn();

        $crawler = $this->client->request('GET', '/vat/periods/2026-11', server: [$this->dialogHeader() => DialogRequest::SHEET]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('November 2026', $crawler->filter('dialog#sheet-page')->text());
        self::assertSame('Paid', $this->text($crawler, '[data-vat-sheet-state]'));
        self::assertSame('Paid 1,500 Kč, expected 1,890 Kč, difference −390 Kč. The period is paid; the difference is for information.', $this->text($crawler, '[data-vat-difference]'));
        self::assertStringContainsString('Paid · Account and variable symbol 1,500 Kč', $this->text($crawler, '[data-vat-payment="m1"]'));
        self::assertSame('/vat/payments/m1/move?back=2026-11', $crawler->filter('[data-vat-payment-move]')->attr('href'));
        self::assertCount(1, $crawler->filter('[data-vat-payment-unpair]'));
        self::assertCount(0, $crawler->filter('[data-vat-payment-reset], [data-vat-settle], [data-vat-reopen]'));
        self::assertSame(['documents', 'schedules', 'plans'], $crawler->filter('[data-vat-part]')->each(static fn (Crawler $row): string => (string) $row->attr('data-vat-part')));
        self::assertSame('Not counted yet', $this->text($crawler, '[data-vat-part="plans"] [data-vat-part-empty]'));
        self::assertCount(2, $crawler->filter('[data-vat-document]'));
        self::assertSame(['output', 'input', 'result'], $crawler->filter('[data-vat-step]')->each(static fn (Crawler $row): string => (string) $row->attr('data-vat-step')));
        self::assertStringNotContainsStringIgnoringCase('overdue', (string) $this->client->getResponse()->getContent());

        // Unpair: the movement is no VAT payment, and the period is not yet paid again.
        $this->client->request('POST', '/vat/payments/m1/unpair', ['_token' => $this->token($crawler), 'back' => '2026-11']);
        self::assertResponseRedirects('/vat/periods/2026-11');
        $crawler = $this->client->followRedirect();
        self::assertSame('Not yet paid', $this->text($crawler, '[data-vat-sheet-state]'));
        self::assertStringContainsString('The payment is no longer a VAT payment.', $this->plain($crawler->text()));

        // Back to the automatic matching from the page, where the movement shows unassigned no more.
        $this->client->request('POST', '/vat/payments/m1/reset', ['_token' => $this->token($crawler)]);
        self::assertResponseRedirects('/vat');
        self::assertStringContainsString('The payment is matched automatically again.', $this->plain($this->client->followRedirect()->text()));
        $crawler = $this->client->request('GET', '/vat?year=2026');
        self::assertSame('Paid', $this->text($crawler, '[data-vat-period="2026-11"] [data-vat-state]'));
    }

    public function testAPaymentWithAnotherSymbolIsPlacedInAPeriod(): void
    {
        $this->paid('m2', '2026-12-27', 1_890, '99');
        $this->signedIn();

        $crawler = $this->client->request('GET', '/vat?year=2026');
        self::assertStringContainsString('Dec 27, 2026 Paid · Other variable symbol 1,890 Kč', $this->text($crawler, '[data-vat-unassigned-payment="m2"]'));
        self::assertSame('/vat/payments/m2/move', $crawler->filter('[data-vat-place]')->attr('href'));

        $crawler = $this->client->request('GET', '/vat/payments/m2/move', server: [$this->dialogHeader() => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame(['2028-01', '2026-11', 'none'], [
            $crawler->filter('select#period option')->first()->attr('value'),
            $crawler->filter('select#period option')->eq($crawler->filter('select#period option')->count() - 2)->attr('value'),
            $crawler->filter('select#period option')->last()->attr('value'),
        ]);
        self::assertSame('Not a VAT payment', $crawler->filter('select#period option')->last()->text());

        $this->client->request('POST', '/vat/payments/m2/move', ['_token' => $this->token($crawler), 'period' => '2026-10']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This period does not exist.', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/vat/payments/m2/move', ['_token' => $this->token($crawler), 'period' => '2026-11', 'back' => '2026-11']);
        self::assertResponseRedirects('/vat/periods/2026-11');
        $crawler = $this->client->followRedirect();
        self::assertSame('Paid', $this->text($crawler, '[data-vat-sheet-state]'));
        self::assertStringContainsString('Placed by hand', $this->text($crawler, '[data-vat-payment="m2"]'));
        self::assertStringContainsString('The payment is placed.', $this->plain($crawler->text()));
        $dialog = $this->client->request('GET', '/vat/payments/m2/move?back=2026-11', server: [$this->dialogHeader() => '1']);
        self::assertSame('2026-11', $dialog->filter('select#period option[selected]')->attr('value'));
        self::assertSame('2026-11', $dialog->filter('input[name="back"]')->attr('value'));
        self::assertCount(1, $crawler->filter('[data-vat-payment-reset]'));
        self::assertSame('Paid 1,890 Kč, as expected.', $this->text($crawler, '[data-vat-difference]'));

        $this->client->request('POST', '/vat/payments/m2/move', ['_token' => $this->token($crawler), 'period' => 'none']);
        self::assertResponseRedirects('/vat');
        $this->client->followRedirect();
        $crawler = $this->client->request('GET', '/vat?year=2026');
        self::assertSame('Not yet paid', $this->text($crawler, '[data-vat-period="2026-11"] [data-vat-state]'));
        self::assertCount(0, $crawler->filter('[data-vat-unassigned-payment]'));
    }

    public function testTheReturnAmountIsEnteredInADialog(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/vat/periods/2026-12/return-amount', server: [$this->dialogHeader() => '1']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The amount of the filed return replaces the estimate of 3,000 Kč as what the period expects.', $this->plain($crawler->text()));
        self::assertSame('refund', $crawler->filter('select#direction option[selected]')->attr('value'));

        $this->client->request('POST', '/vat/periods/2026-12/return-amount', ['_token' => $this->token($crawler), 'amount' => 'lots', 'direction' => 'refund']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter an amount, such as 1500.', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/vat/periods/2026-12/return-amount', ['_token' => $this->token($crawler), 'amount' => '2 800', 'direction' => 'refund']);
        self::assertResponseRedirects('/vat/periods/2026-12');
        $crawler = $this->client->followRedirect();
        self::assertSame('Amount from the return: 2,800 Kč, the estimate was 3,000 Kč.', $this->text($crawler, '[data-vat-return-amount-text]'));
        self::assertStringContainsString('The amount from the return is saved.', $this->plain($crawler->text()));
        self::assertStringContainsString('Refund 2,800', $this->text($crawler, '[data-vat-summary]'));
        self::assertSame('Amount from the return', $this->text($crawler, '[data-vat-sheet-state]'));

        $this->client->request('POST', '/vat/periods/2026-12/return-amount', ['_token' => $this->token($crawler), 'amount' => '-2 700', 'direction' => 'refund']);
        self::assertSame('Amount from the return: 2,700 Kč, the estimate was 3,000 Kč.', $this->text($this->client->followRedirect(), '[data-vat-return-amount-text]'));

        $this->client->request('POST', '/vat/periods/2026-11/return-amount', ['_token' => $this->token($crawler), 'amount' => '1500', 'direction' => 'payable']);
        self::assertResponseRedirects('/vat/periods/2026-11');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('To pay 1,500', $this->text($crawler, '[data-vat-summary]'));

        $this->client->request('POST', '/vat/periods/2026-12/return-amount/clear', ['_token' => $this->token($crawler)]);
        self::assertResponseRedirects('/vat/periods/2026-12');
        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('[data-vat-return-amount-text], [data-vat-clear-return-amount]'));
        self::assertStringContainsString('The amount from the return is removed.', $this->plain($crawler->text()));

        $this->client->request('POST', '/vat/periods/2026-12/return-amount/clear', ['_token' => $this->token($crawler)]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAPeriodIsMarkedPaidAndOpenedAgain(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/vat/periods/2026-11/settle', server: [$this->dialogHeader() => '1']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Mark November 2026 as paid', $this->plain($crawler->text()));

        $this->client->request('POST', '/vat/periods/2026-11/settle', ['_token' => $this->token($crawler), 'note' => str_repeat('x', 1001)]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The note is too long.', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/vat/periods/2026-11/settle', ['_token' => $this->token($crawler), 'note' => 'Paid from the private account']);
        self::assertResponseRedirects('/vat/periods/2026-11');
        $crawler = $this->client->followRedirect();
        self::assertSame('Paid', $this->text($crawler, '[data-vat-sheet-state]'));
        self::assertSame('Marked as paid by hand.', $this->text($crawler, '[data-vat-settled-mark]'));
        self::assertStringContainsString('The period is marked as paid.', $this->plain($crawler->text()));
        self::assertCount(0, $crawler->filter('[data-vat-settle]'));

        $this->client->request('POST', '/vat/periods/2026-11/reopen', ['_token' => $this->token($crawler)]);
        self::assertResponseRedirects('/vat/periods/2026-11');
        $crawler = $this->client->followRedirect();
        self::assertSame('Not yet paid', $this->text($crawler, '[data-vat-sheet-state]'));
        self::assertCount(1, $crawler->filter('[data-vat-settle]'));
        self::assertStringContainsString('The period is open again.', $this->plain($crawler->text()));

        $this->client->request('POST', '/vat/periods/2026-11/reopen', ['_token' => $this->token($crawler)]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testWhereTheFirmPaysItsVatIsADialogWithTheCzechSuggestion(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/vat/settings', server: [$this->dialogHeader() => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame(self::OFFICE, $crawler->filter('input#officeAccount')->attr('value'));
        self::assertStringContainsString('Suggested: 705-<number of your tax office>/0710', $this->plain($crawler->text()));
        self::assertStringContainsString('Empty takes the suggested one: 12345678.', $this->plain($crawler->text()));
        self::assertStringStartsWith('https://', (string) $crawler->filter('[data-vat-settings-source]')->attr('href'));

        $this->client->request('POST', '/vat/settings', ['_token' => $this->token($crawler), 'officeAccount' => ' ', 'variableSymbol' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter the account of the tax office.', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/vat/settings', ['_token' => $this->token($crawler), 'officeAccount' => '705-77628031/0710', 'variableSymbol' => str_repeat('1', 40)]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The variable symbol is too long.', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/vat/settings', ['_token' => $this->token($crawler), 'officeAccount' => '705-77628031/0710', 'variableSymbol' => '']);
        self::assertResponseRedirects('/vat');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Where the firm pays its VAT is saved.', $this->plain($crawler->text()));
        $crawler = $this->client->request('GET', '/vat/settings');
        self::assertSame('12345678', $crawler->filter('input#variableSymbol')->attr('value'));
    }

    public function testWrongAddressesAndTokensAreRefused(): void
    {
        $this->signedIn();

        foreach (['/vat?year=26', '/vat?year=2099', '/vat/periods/2026-10', '/vat/periods/x', '/vat/periods/2026-10/settle', '/vat/periods/2026-10/return-amount'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(404, $page);
        }
        foreach (['/vat/periods/2026-11/settle', '/vat/periods/2026-11/reopen', '/vat/periods/2026-11/return-amount', '/vat/periods/2026-11/return-amount/clear', '/vat/payments/m1/move', '/vat/payments/m1/unpair', '/vat/payments/m1/reset', '/vat/settings'] as $action) {
            $this->client->request('POST', $action, ['_token' => 'wrong']);
            self::assertResponseStatusCodeSame(403, $action);
        }
    }

    public function testAGuestIsRefusedAndSeesNoMenuItem(): void
    {
        $this->signedIn(Role::Guest);

        foreach (['/vat', '/vat/periods/2026-11', '/vat/periods/2026-11/settle', '/vat/periods/2026-11/return-amount', '/vat/payments/m1/move', '/vat/settings'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }
        $crawler = $this->client->request('GET', '/account/two-factor');
        self::assertCount(0, $crawler->filter('#sidebar a[href="/vat"]'));
    }

    public function testAMissingPayerDayLinksToTheVatOfTheFirm(): void
    {
        $this->signedIn(payerSince: null);

        $crawler = $this->client->request('GET', '/vat?year=2026');

        self::assertResponseIsSuccessful();
        self::assertSame('The day the firm became a VAT payer is missing', $this->text($crawler, '[data-vat-empty="payer_date_missing"] h2, [data-vat-empty="payer_date_missing"] h3'));
        self::assertStringContainsString('The firm is a VAT payer, but the day it became one is missing.', $this->text($crawler, '[data-vat-warning="payer_date_missing"]'));
        self::assertSame('/settings/firm/vat', $crawler->filter('[data-vat-firm-vat]')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-vat-periods]'));
    }

    private function signedIn(Role $role = Role::Member, ?string $payerSince = '2026-11-12'): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Sole Trader', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345678'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, ''));
        if (null === $payerSince) {
            $em->persist(new VatStatusPeriod(VatStatus::Registered, VatPeriodLength::Month, null));
        } else {
            $em->persist(new VatStatusPeriod(VatStatus::NotRegistered, null, null));
            $em->persist(new VatStatusPeriod(VatStatus::Registered, VatPeriodLength::Month, new \DateTimeImmutable($payerSince)));
        }
        $em->persist(new VatSettings(self::OFFICE, self::SYMBOL));
        $em->flush();
        self::getContainer()->set(VatPeriods::class, $this->vat());
    }

    /**
     * The CSRF token of the session: from a form of the page, else from the settings dialog.
     */
    private function token(Crawler $crawler): string
    {
        $field = $crawler->filter('form[action^="/vat"] input[name="_token"]');
        if (0 === $field->count()) {
            $field = $this->client->request('GET', '/vat/settings', server: [$this->dialogHeader() => '1'])->filter('form[action^="/vat"] input[name="_token"]');
        }

        return (string) $field->first()->attr('value');
    }

    private function text(Crawler $crawler, string $selector): string
    {
        return $this->plain($crawler->filter($selector)->text());
    }

    /**
     * The text with every run of spaces, the no-break ones included, as one space.
     */
    private function plain(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{a0}]+/u', ' ', $text));
    }

    private function dialogHeader(): string
    {
        return 'HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER));
    }
}
