<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Identity\Domain\Role;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\CorrectionInput;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\CorrectionType;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\PaymentSource;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Tests\Party\Support\FirmAccounts;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The pay dialog and the payments of an invoice. Every invoice here is 1 000.00
 * without VAT at the base rate: 1 210.00 CZK to pay.
 *
 * @phpstan-type Pay array{_token: string, _submission: string, return: string, receivedOn: string, amount: string, method: string, note: string, settle?: string}
 */
final class InvoicePaymentControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string IBAN = 'CZ6508000000192000145399';
    private const array DIALOG = ['HTTP_X_FORESI_DIALOG' => '1'];

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private TenantContext $context;
    private TenantId $tenant;
    private LegalEntity $firm;
    private Series $series;
    private Party $customer;

    protected function setUp(): void
    {
        // 23:30 in Prague is the next day in UTC an hour later: the date is the firm's.
        self::mockTime('2026-11-03 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
    }

    public function testPayingInTheDialogStoresThePaymentAndRedirectsAsJson(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialogValues($invoice, 'tab=unpaid');
        $values['note'] = 'Paid at the counter';
        $values['method'] = PaymentMethod::Cash->value;

        $this->client->request('POST', $this->payUrl($invoice), $values, server: self::DIALOG);

        self::assertResponseIsSuccessful();
        self::assertSame(['location' => '/invoices?tab=unpaid'], json_decode((string) $this->client->getResponse()->getContent(), true));
        $rows = $this->allocations();
        self::assertCount(1, $rows);
        self::assertSame($invoice->id(), $rows[0]['invoice_id']);
        self::assertSame(121000, $rows[0]['amount_minor']);
        self::assertSame(0, $rows[0]['settlement_minor']);
        self::assertSame(121000, $rows[0]['payment_amount']);
        self::assertSame('2026-11-03', substr($rows[0]['received_on'], 0, 10));
        self::assertSame('cash', $rows[0]['method']);
        self::assertSame('manual', $rows[0]['source']);
        self::assertSame('Paid at the counter', $rows[0]['note']);
        // The flash waits for the page the dialog leads to.
        $this->client->request('GET', '/invoices?tab=unpaid');
        self::assertAnySelectorTextContains('[role="status"]', $this->nb('A payment of 1,210.00 Kč was recorded for invoice 20260001.'));
    }

    public function testTheDialogPrefillsTheRemainingAmount(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->paid($invoice, '500.00');

        $dialog = $this->openDialog($invoice);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('<dialog', ltrim((string) $this->client->getResponse()->getContent()));
        self::assertSame('Pay invoice 20260001', $dialog->filter('dialog h2')->text());
        self::assertStringContainsString('max-w-[400px]', (string) $dialog->filter('dialog')->attr('class'));
        self::assertStringContainsString('max-sm:h-dvh', (string) $dialog->filter('dialog')->attr('class'), 'full screen on a phone');
        self::assertSame('710.00', $dialog->filter('#pay-amount')->attr('value'));
        self::assertSame('2026-11-03', $dialog->filter('#pay-receivedOn')->attr('value'));
        self::assertSame('2026-11-03', $dialog->filter('#pay-receivedOn')->attr('max'));
        self::assertSame('bank_transfer', $dialog->filter('#pay-method option[selected]')->attr('value'));
        self::assertSame(['bank_transfer', 'cash', 'card'], $dialog->filter('#pay-method option')->each(static fn (Crawler $option): string => (string) $option->attr('value')));
        self::assertSame('71000', $dialog->filter('[data-controller~="payment-amount"]')->attr('data-payment-amount-remaining-value'));
        self::assertSelectorTextContains('dialog', $this->nb('Remaining 710.00 Kč of 1,210.00 Kč'));
        // Cancel first, then the action, which has the focus so Enter records it.
        $buttons = $dialog->filter('footer button');
        self::assertSame('dialog#dismiss', $buttons->first()->attr('data-action'));
        self::assertSame('Record payment', trim($dialog->filter('#pay-submit')->text()));
        self::assertSame('pay-form', $dialog->filter('#pay-submit')->attr('form'));
        self::assertNotNull($dialog->filter('#pay-amount')->attr('autofocus'), 'the amount is ready to type over');
    }

    public function testAnOverpaidInvoicePrefillsNothingBelowZero(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->paid($invoice, '1300.00');

        $dialog = $this->openDialog($invoice);

        self::assertSame('', $dialog->filter('#pay-amount')->attr('value'));
        self::assertSame('0', $dialog->filter('[data-controller~="payment-amount"]')->attr('data-payment-amount-remaining-value'));
        self::assertSelectorTextContains('dialog [data-settled]', 'This invoice is paid already.');
    }

    public function testAnAmountAboveTheRemainingWarnsAndStillSaves(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialogValues($invoice);
        $values['amount'] = '1,300';

        $this->client->request('POST', $this->payUrl($invoice), $values);

        self::assertResponseRedirects('/invoices');
        self::assertSame(130000, $this->allocations()[0]['amount_minor']);
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role="status"]', $this->nb('Invoice 20260001 is overpaid by 90.00 Kč.'));
        self::assertSelectorTextContains('tbody tr', 'Overpaid');
    }

    public function testASmallDifferenceIsClosedOnlyWhenAsked(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $dialog = $this->openDialog($invoice);
        // The country's limit, in minor units; the box shows only for a difference within it.
        self::assertSame('99', $dialog->filter('[data-controller~="payment-amount"]')->attr('data-payment-amount-limit-value'));
        self::assertSame('', (string) $dialog->filter('[data-settle]')->attr('hidden'));
        self::assertSame('1', $dialog->filter('#pay-settle')->attr('value'));

        $values = $this->dialogValues($invoice);
        $values['amount'] = '1209.50';
        $this->client->request('POST', $this->payUrl($invoice), $values);
        self::assertResponseRedirects('/invoices');
        self::assertSame([120950, 0], [$this->allocations()[0]['amount_minor'], $this->allocations()[0]['settlement_minor']], 'no box ticked, no settlement');

        $other = $this->issued();
        $values = $this->dialogValues($other);
        $values['amount'] = '1209.50';
        $values['settle'] = '1';
        $this->client->request('POST', $this->payUrl($other), $values);
        self::assertResponseRedirects('/invoices');
        $rows = $this->allocations();
        self::assertSame([120950, 50, 120950], [$rows[1]['amount_minor'], $rows[1]['settlement_minor'], $rows[1]['payment_amount']]);
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role="status"]', $this->nb('A payment of 1,209.50 Kč was recorded for invoice 20260002. The difference of 0.50 Kč was written off.'));
    }

    public function testAnAmountAboveTheRemainingByCoinsCanCloseItToo(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialogValues($invoice);
        $values['amount'] = '1210.40';
        $values['settle'] = '1';

        $this->client->request('POST', $this->payUrl($invoice), $values);

        self::assertResponseRedirects('/invoices');
        self::assertSame([121040, -40], [$this->allocations()[0]['amount_minor'], $this->allocations()[0]['settlement_minor']]);
    }

    public function testADifferenceAboveTheLimitIsNotWrittenOff(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialogValues($invoice);
        $values['amount'] = '1200';
        $values['settle'] = '1';

        $dialog = $this->post($invoice, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($this->nb('Only a difference of up to 0.99 Kč can be written off.'), $dialog->filter('#pay-settle-error')->text());
        self::assertSame([], $this->allocations());
    }

    public function testAFirmWhoseCountryGivesNoLimitIsOfferedNoWriteOff(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        // Writing off coins follows the firm's country, not the currency of the invoice.
        $this->em()->getConnection()->executeStatement("UPDATE legal_entity SET address_country_code = 'SK' WHERE id = ?", [$this->firm->id()]);
        $this->em()->clear();

        $dialog = $this->openDialog($invoice);
        self::assertNull($dialog->filter('[data-controller~="payment-amount"]')->attr('data-payment-amount-limit-value'));
        self::assertCount(0, $dialog->filter('[data-settle]'));

        $values = $this->dialogValues($invoice);
        $values['amount'] = '1209.50';
        $values['settle'] = '1';
        $this->post($invoice, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->allocations());
    }

    public function testAZeroOrNegativeAmountAnswers422(): void
    {
        $this->signedIn();
        $invoice = $this->issued();

        foreach (['0' => 'Enter an amount above zero.', '-5' => 'Enter an amount above zero.', '' => 'Enter an amount above zero.', 'ten' => 'Enter an amount, such as 1,210.50.', '1.234' => 'Enter an amount, such as 1,210.50.'] as $amount => $message) {
            $values = $this->dialogValues($invoice);
            $values['amount'] = (string) $amount;

            $dialog = $this->post($invoice, $values);

            self::assertResponseStatusCodeSame(422, (string) $amount);
            self::assertStringStartsWith('<dialog', ltrim((string) $this->client->getResponse()->getContent()));
            self::assertSame($message, $dialog->filter('#pay-amount-error')->text(), (string) $amount);
            self::assertSame('true', $dialog->filter('#pay-amount')->attr('aria-invalid'));
            self::assertSame((string) $amount, $dialog->filter('#pay-amount')->attr('value'), 'what was typed stays');
        }
        self::assertSame([], $this->allocations());
    }

    public function testAFutureDateAnswers422(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialogValues($invoice);
        $values['receivedOn'] = '2026-11-04';

        $dialog = $this->post($invoice, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('A payment cannot come from the future.', $dialog->filter('#pay-receivedOn-error')->text());
        self::assertSame('2026-11-04', $dialog->filter('#pay-receivedOn')->attr('value'));

        $values['receivedOn'] = '3.11.2026';
        $dialog = $this->post($invoice, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Enter a valid date.', $dialog->filter('#pay-receivedOn-error')->text());
        self::assertSame([], $this->allocations());
    }

    public function testANoteTooLongAnswers422(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialogValues($invoice);
        $values['note'] = str_repeat('a', 501);

        $dialog = $this->post($invoice, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Keep the note under 500 characters.', $dialog->filter('#pay-note-error')->text());
        self::assertSame([], $this->allocations());
    }

    public function testTheSameUrlWorksAsAPageWithoutJs(): void
    {
        $this->signedIn();
        $invoice = $this->issued();

        $crawler = $this->client->request('GET', $this->payUrl($invoice).'?return='.rawurlencode('tab=overdue&q=Consulting'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Pay invoice 20260001');
        self::assertSelectorExists('main form#pay-form');
        self::assertSame('/invoices?tab=overdue&q=Consulting', $crawler->filter('main a')->reduce(static fn (Crawler $link): bool => 'Cancel' === trim($link->text()))->attr('href'));

        $values = $crawler->filter('form#pay-form')->form()->getPhpValues();
        $this->client->request('POST', $this->payUrl($invoice), $values);

        self::assertResponseRedirects('/invoices?tab=overdue&q=Consulting');
        self::assertCount(1, $this->allocations());
    }

    public function testTheSubmissionTokenStopsADoubleSubmit(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialogValues($invoice);
        $values['amount'] = '100';

        $this->client->request('POST', $this->payUrl($invoice), $values, server: self::DIALOG);
        $this->client->request('POST', $this->payUrl($invoice), $values, server: self::DIALOG);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->allocations());
        $this->client->request('GET', '/invoices');
        self::assertSelectorTextContains('main', 'This payment is recorded already.');
    }

    public function testAWrongCsrfTokenRecordsNothing(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialogValues($invoice);
        $values['_token'] = 'wrong';

        $this->client->request('POST', $this->payUrl($invoice), $values);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->allocations());
    }

    public function testATrashedInvoiceIsNotPaid(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialogValues($invoice);
        $this->scoped(fn () => self::getContainer()->get(TrashInvoice::class)->trash($invoice->id(), 'Wrong customer'));

        $this->client->request('GET', $this->payUrl($invoice), server: self::DIALOG);
        self::assertSame(['location' => '/invoices'], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->client->request('POST', $this->payUrl($invoice), $values);
        self::assertResponseRedirects('/invoices');
        self::assertSame([], $this->allocations());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Invoice 20260001 is in the trash. Restore it first.');
    }

    public function testRemovingAPaymentRestoresUnpaid(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $paymentId = $this->paid($invoice, '1210.00');

        $crawler = $this->client->request('GET', '/invoices/'.$invoice->id().'/edit');
        $panel = $crawler->filter('[data-payments]');
        self::assertCount(1, $panel);
        self::assertCount(0, $crawler->filter('form#invoice-form [data-payments]'), 'the panel is outside the invoice form');
        self::assertStringContainsString('2026-11-03', $panel->filter('[data-payment]')->text());
        self::assertStringContainsString($this->nb('1,210.00 Kč'), $panel->filter('[data-payment]')->text());
        self::assertStringContainsString('Bank transfer', $panel->filter('[data-payment]')->text());
        $remove = $panel->filter('form[action="/invoices/'.$invoice->id().'/payments/'.$paymentId.'/cancel"]');
        self::assertSame($this->nb('Remove the payment of 1,210.00 Kč?'), $remove->attr('data-confirm-title'));
        self::assertSame('The invoice counts as unpaid again by that amount.', $remove->attr('data-confirm-text'));

        $this->client->submit($remove->form());

        self::assertResponseRedirects('/invoices/'.$invoice->id().'/edit');
        self::assertSame([], $this->allocations());
        $cancelled = $this->connection()->fetchOne('SELECT cancelled_at FROM payment WHERE id = ?', [$paymentId]);
        self::assertNotNull($cancelled, 'the payment stays, cancelled');
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role="status"]', $this->nb('The payment of 1,210.00 Kč was removed.'));
        self::assertSelectorTextSame('header h1 + span', 'Issued');
        self::assertSelectorTextContains('[data-payments]', 'No payments yet.');
    }

    public function testRemovingAPaymentOfSeveralInvoicesNamesThemAll(): void
    {
        $this->signedIn();
        $first = $this->issued();
        $second = $this->issued();
        $paymentId = $this->scoped(function () use ($first, $second): string {
            $part = Money::of('1210.00', Currency::CZK);

            return self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-03'), Money::of('2420.00', Currency::CZK), PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($first->id(), $part), new AllocationRequest($second->id(), $part)]))->id();
        });

        $crawler = $this->client->request('GET', '/invoices/'.$first->id().'/edit');
        $remove = $crawler->filter('[data-payments] form[action="/invoices/'.$first->id().'/payments/'.$paymentId.'/cancel"]');
        self::assertSame('The payment also pays invoice '.$second->number().'. It is removed from all of them, and each counts as unpaid again by its part.', $remove->attr('data-confirm-text'));

        $this->client->submit($remove->form());

        self::assertSame([], $this->allocations());
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role="status"]', $this->nb('The payment of 2,420.00 Kč was removed from invoices '.$first->number().', '.$second->number().'.'));
        $translator = self::getContainer()->get('translator');
        self::assertSame('Úhrada platí i fakturu 20260002. Odebere se ze všech a každá bude o svou část znovu neuhrazená.', $translator->trans('invoice.payment.panel.remove_text_shared', ['count' => 1, 'numbers' => '20260002'], null, 'cs'));
        self::assertSame('Úhrada platí i faktury 20260002, 20260003. Odebere se ze všech, každá bude o svou část znovu neuhrazená a bankovní pohyb se vrátí mezi nespárované.', $translator->trans('invoice.payment.panel.remove_text_shared_bank', ['count' => 2, 'numbers' => '20260002, 20260003'], null, 'cs'));
    }

    public function testRemovingNeedsAToken(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $paymentId = $this->paid($invoice, '100.00');

        $this->client->request('POST', '/invoices/'.$invoice->id().'/payments/'.$paymentId.'/cancel', ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->allocations());
    }

    public function testAPaymentOfAnotherInvoiceIs404(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $other = $this->issued();
        $paymentId = $this->paid($other, '100.00');
        $token = $this->client->request('GET', '/invoices/'.$other->id().'/edit')->filter('[data-payments] form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/invoices/'.$invoice->id().'/payments/'.$paymentId.'/cancel', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->allocations());
    }

    public function testTheHistorySaysWhoPaidWhatAndWhoRemovedIt(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialogValues($invoice);
        $values['amount'] = '500';
        $this->client->request('POST', $this->payUrl($invoice), $values);
        $paymentId = $this->scalar('SELECT id FROM payment');
        $token = $this->client->request('GET', '/invoices/'.$invoice->id().'/edit')->filter('[data-payments] form input[name="_token"]')->attr('value');
        $this->client->request('POST', '/invoices/'.$invoice->id().'/payments/'.$paymentId.'/cancel', ['_token' => $token]);

        $items = $this->client->request('GET', '/invoices/'.$invoice->id().'/history')->filter('[data-invoice-history] li');

        $texts = $items->each(static fn (Crawler $item): string => $item->filter('span')->last()->text());
        self::assertSame($this->nb('Example Company: payment of 500.00 Kč recorded'), $texts[1]);
        self::assertSame($this->nb('Example Company: payment of 500.00 Kč removed'), $texts[2]);
        self::assertSame(['banknote', 'banknote-x'], \array_slice($items->each(static fn (Crawler $item): string => (string) $item->attr('data-history-icon')), 1, 2));
    }

    public function testAnotherTenantsInvoiceIs404(): void
    {
        $this->signedIn();
        $foreign = $this->foreignInvoice();

        $this->client->request('GET', '/invoices/'.$foreign.'/pay');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/invoices/'.$foreign.'/pay', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('0', $this->scalar('SELECT COUNT(*) FROM payment'));
    }

    public function testAClientRoleGets403(): void
    {
        $this->signedIn(Role::Guest);
        $id = '019a0000-0000-7000-8000-000000000001';

        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, '/invoices/'.$id.'/pay');
            self::assertResponseStatusCodeSame(403, $method);
        }
        $this->client->request('POST', '/invoices/'.$id.'/payments/'.$id.'/cancel');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheRefundActionRecordsWhatIsToRefundAndRefusesMore(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->paid($invoice, '1210.00');
        $this->credited($invoice, '-400.00');

        $panel = $this->client->request('GET', '/invoices/'.$invoice->id().'/edit')->filter('[data-payments]');
        self::assertCount(1, $panel->filter('#invoice-refund'), 'to refund, the panel offers the refund');
        self::assertCount(0, $panel->filter('#invoice-pay'));
        $url = '/invoices/'.$invoice->id().'/refund';
        $this->client->request('GET', $url, server: self::DIALOG);
        $values = new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost/')->filter('form#refund-form')->form()->getPhpValues();
        self::assertSame('484.00', $values['amount'], 'what is to refund is filled in');

        $this->client->request('POST', $url, ['amount' => '484.01'] + $values, server: self::DIALOG);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form#refund-form', 'more than is to refund');

        $this->client->request('POST', $url, $values, server: self::DIALOG);
        self::assertResponseIsSuccessful();
        $rows = $this->allocations();
        self::assertSame([121000, -48400], array_column($rows, 'amount_minor'), 'the refund lowers what was paid');
        self::assertSame('refund', $this->scalar('SELECT direction FROM payment WHERE amount_minor = 48400'));
        $panel = $this->client->request('GET', '/invoices/'.$invoice->id().'/edit')->filter('[data-payments]');
        self::assertCount(0, $panel->filter('#invoice-refund'), 'nothing is left to refund');
        self::assertCount(2, $panel->filter('[data-payment]'));
    }

    public function testAFullyCreditedUnpaidInvoiceOffersNeitherPayNorRefund(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->credited($invoice, '-1000.00');

        $panel = $this->client->request('GET', '/invoices/'.$invoice->id().'/edit')->filter('[data-payments]');

        self::assertCount(0, $panel->filter('#invoice-pay'));
        self::assertCount(0, $panel->filter('#invoice-refund'));
        $this->client->request('GET', '/invoices/'.$invoice->id().'/refund');
        self::assertResponseRedirects('/invoices/'.$invoice->id().'/edit');
    }

    private function credited(Invoice $invoice, string $difference): void
    {
        $this->scoped(function () use ($invoice, $difference): void {
            $line = new LineInput('Work', Quantity::fromString('-1'), 'h', Money::of(ltrim($difference, '-'), Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
            self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput($this->firm->id(), null, null, $this->customer->id(), null, new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2026-11-15'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Credit', '', '', DocumentLanguage::Czech, [$line], true, DocumentKind::CorrectionDocument, correction: new CorrectionInput($invoice->id(), 'Returned goods', CorrectionType::Change, new \DateTimeImmutable('2026-11-02'))));
        });
    }

    private function nb(string $text): string
    {
        return str_replace(' Kč', "\u{a0}Kč", $text);
    }

    private function payUrl(Invoice $invoice): string
    {
        return '/invoices/'.$invoice->id().'/pay';
    }

    private function openDialog(Invoice $invoice, string $return = ''): Crawler
    {
        $this->client->request('GET', $this->payUrl($invoice).('' === $return ? '' : '?return='.rawurlencode($return)), server: self::DIALOG);

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost/');
    }

    /**
     * What the dialog would post as it opens.
     *
     * @return Pay
     */
    private function dialogValues(Invoice $invoice, string $return = ''): array
    {
        /** @var Pay $values */
        $values = $this->openDialog($invoice, $return)->filter('form#pay-form')->form()->getPhpValues();

        return $values;
    }

    /**
     * @param Pay $values
     */
    private function post(Invoice $invoice, array $values): Crawler
    {
        $this->client->request('POST', $this->payUrl($invoice), $values, server: self::DIALOG);

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost/');
    }

    /**
     * The allocations as stored, with their payment.
     *
     * @return list<array{invoice_id: string, amount_minor: int, settlement_minor: int, payment_amount: int, received_on: string, method: string, source: string, note: string}>
     */
    private function allocations(): array
    {
        $this->em()->clear();
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT a.invoice_id, a.amount_minor, a.settlement_minor, p.amount_minor AS payment_amount, p.received_on, p.method, p.source, p.note
            FROM payment_allocation a JOIN payment p ON p.id = a.payment_id ORDER BY p.created_at',
        );
        $text = static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '';

        return array_map(static fn (array $row): array => [
            'invoice_id' => $text($row['invoice_id']),
            'amount_minor' => (int) $text($row['amount_minor']),
            'settlement_minor' => (int) $text($row['settlement_minor']),
            'payment_amount' => (int) $text($row['payment_amount']),
            'received_on' => $text($row['received_on']),
            'method' => $text($row['method']),
            'source' => $text($row['source']),
            'note' => $text($row['note']),
        ], $rows);
    }

    private function scalar(string $sql): string
    {
        $value = $this->connection()->fetchOne($sql);

        return \is_scalar($value) ? (string) $value : '';
    }

    private function paid(Invoice $invoice, string $amount): string
    {
        return $this->scoped(function () use ($invoice, $amount): string {
            $money = Money::of($amount, Currency::CZK);

            return self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($this->firm->id(), new \DateTimeImmutable('2026-11-03'), $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoice->id(), $money)]))->id();
        });
    }

    private function issued(): Invoice
    {
        return $this->scoped(function (): Invoice {
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

            return self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput($this->firm->id(), $this->series->id(), null, $this->customer->id(), null, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-15'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Consulting', '', '', DocumentLanguage::Czech, [$line], true));
        });
    }

    private function foreignInvoice(): string
    {
        $tenant = $this->browser->fixtures->tenant('Foreign Tenant '.bin2hex(random_bytes(2)));
        $this->context->switchTo($tenant);
        try {
            [$firm, $series, $customer] = $this->firmWithSeries();
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('100.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

            return self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput($firm->id(), $series->id(), null, $customer->id(), null, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-12-01'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Foreign', '', '', DocumentLanguage::Czech, [$line], true))->id();
        } finally {
            $this->context->reset();
        }
    }

    /**
     * @return array{LegalEntity, Series, Party}
     */
    private function firmWithSeries(): array
    {
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->em()->persist($firm);
        FirmAccounts::add($this->em(), $firm, self::IBAN);
        $series = new Series($firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $series->markDefault();
        $this->em()->persist($series);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em()->persist($customer);
        $this->em()->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em()->flush();

        return [$firm, $series, $customer];
    }

    private function signedIn(Role $role = Role::Owner): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], role: $role);
        $this->context->switchTo($this->tenant);
        [$this->firm, $this->series, $this->customer] = $this->firmWithSeries();
        $this->context->reset();
        $this->browser->signIn();
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function scoped(callable $work): mixed
    {
        $this->context->switchTo($this->tenant);
        try {
            return $work();
        } finally {
            $this->context->reset();
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
