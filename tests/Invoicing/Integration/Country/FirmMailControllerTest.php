<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Doctrine\DBAL\Connection;
use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\InvoiceBalances;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\MarkDelivered;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Application\ReminderSettings;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Invoicing\Infrastructure\Doctrine\DoctrineReminderSettings;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Invoicing\Infrastructure\Support\UnauthoritativeBalances;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Tests\Support\Mail\RecordingMailGateway;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class FirmMailControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        $this->boot();
        RecordingMailGateway::reset();
        Clock::set(new MockClock('2026-11-02 12:00:00'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        RecordingMailGateway::reset();
        parent::tearDown();
    }

    public function testThePageListsTheTemplatesAndEachOpensInASheet(): void
    {
        $this->signedInFirm();
        $this->saveTemplates(['subject' => ['en' => 'Own {Document number}']]);

        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('main nav[aria-label="E-mail settings sections"] a'));
        self::assertSame('page', $crawler->filter('main nav[aria-label="Firm sections"] a[href$="/mail"]')->attr('aria-current'));
        // The documents first, the reminder last, each a link that opens the sheet.
        self::assertSame(
            ['invoice', 'advance', 'tax_document', 'correction', 'reminder'],
            $crawler->filter('#mail-templates [data-mail-template]')->each(static fn ($row): string => (string) $row->attr('data-mail-template')),
        );
        $links = $crawler->filter('#mail-templates a[data-sheet]');
        self::assertSame('/settings/firm/mail/templates/invoice', $links->first()->attr('href'));
        self::assertSame('dialog', $links->first()->attr('aria-haspopup'));
        // Per language, whether the firm wrote its own text.
        self::assertSame(['Czech: built-in text', 'English: own text'], $crawler->filter('[data-mail-template="invoice"] li')->each(static fn ($badge): string => trim($badge->text())));
        self::assertSame(['Czech: built-in text', 'English: built-in text'], $crawler->filter('[data-mail-template="reminder"] li')->each(static fn ($badge): string => trim($badge->text())));
        // No editor and no save of all templates on the list.
        self::assertCount(0, $crawler->filter('#subject-cs, textarea'));
        self::assertCount(1, $crawler->filter('form#mail-reminders'));
    }

    public function testThePreviewFillsTheVariablesWithSampleValues(): void
    {
        $this->signedInFirm();
        $token = $this->previewToken();

        $this->client->request('POST', '/settings/firm/mail/templates/reminder/preview/cs', [
            '_token' => $token,
            'subject' => 'Upomínka {Číslo dokladu}',
            'text' => "Dobrý den, **{Název odběratele}**,\nzbývá {Zbývá uhradit}.\n\n[Otevřít]({Odkaz na doklad})",
        ]);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertSelectorTextContains('[data-mail-preview-subject]', 'Upomínka 20260042');
        self::assertStringContainsString('<strong>Vzorový odběratel s.r.o.</strong>', $content);
        self::assertMatchesRegularExpression('/zbývá 7\\s260,00\\sKč\\./u', $content);
        self::assertStringContainsString('<a href="https://example.com/d/sample" rel="noopener noreferrer nofollow">Otevřít</a>', $content);

        $this->client->request('POST', '/settings/firm/mail/templates/invoice/preview/en', ['_token' => 'wrong', 'text' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testReminderSettingsAreStored(): void
    {
        $this->signedInFirm();
        $crawler = $this->client->request('GET', $this->url());
        self::assertCount(0, $crawler->filter('#reminders-enabled[checked]'), 'off until the owner switches it on');
        self::assertSame(['7', '7', ''], [$crawler->filter('#reminders-first')->attr('value'), $crawler->filter('#reminders-every')->attr('value'), $crawler->filter('#reminders-minimum')->attr('value')]);
        self::assertSelectorTextContains('#reminders-card', 'Up to 10 reminders');

        $this->saveTemplates(['reminders' => ['enabled' => '1', 'firstAfterDays' => '10', 'everyDays' => '5', 'minimum' => '500.50']]);

        self::assertResponseRedirects($this->url());
        self::assertEquals(
            ['enabled' => 1, 'enabled_on' => '2026-11-02', 'first_after_days' => 10, 'every_days' => 5, 'minimum_minor' => 50050, 'minimum_currency' => 'CZK'],
            $this->connection()->fetchAssociative('SELECT enabled, enabled_on, first_after_days, every_days, minimum_minor, minimum_currency FROM firm_reminders'),
        );
        $crawler = $this->client->request('GET', $this->url());
        self::assertCount(1, $crawler->filter('#reminders-enabled[checked]'));
        self::assertSame(['10', '5', '500.50'], [$crawler->filter('#reminders-first')->attr('value'), $crawler->filter('#reminders-every')->attr('value'), $crawler->filter('#reminders-minimum')->attr('value')]);

        // A blank minimum is no minimum.
        $this->saveTemplates(['reminders' => ['minimum' => '']]);
        self::assertResponseRedirects($this->url());
        self::assertSame(['minimum_minor' => null, 'minimum_currency' => null], $this->connection()->fetchAssociative('SELECT minimum_minor, minimum_currency FROM firm_reminders'));
    }

    public function testAdvancesAreRemindedOnlyWhenSwitchedOn(): void
    {
        $this->signedInFirm();
        $crawler = $this->client->request('GET', $this->url());
        self::assertCount(0, $crawler->filter('#reminders-advances[checked]'));

        $this->saveTemplates(['reminders' => ['advances' => '1']]);

        self::assertResponseRedirects($this->url());
        self::assertEquals(['remind_advances' => 1, 'enabled' => 0], $this->connection()->fetchAssociative('SELECT remind_advances, enabled FROM firm_reminders'));
        $crawler = $this->client->request('GET', $this->url());
        self::assertCount(1, $crawler->filter('#reminders-advances[checked]'));

        $form = $crawler->filter('#mail-reminders')->form()->getPhpValues();
        self::assertIsArray($form['reminders']);
        unset($form['reminders']['advances']);
        $this->client->request('POST', $this->url(), $form);
        self::assertEquals(['remind_advances' => 0], $this->connection()->fetchAssociative('SELECT remind_advances FROM firm_reminders'));
    }

    public function testThePreviewSaysWhatSwitchingOnTodayWouldSendWhileOff(): void
    {
        $this->signedInFirm();

        $this->client->request('GET', $this->url());

        self::assertSelectorTextContains('#reminders-preview h4', 'If you switched reminders on today');
        self::assertSelectorTextContains('#reminders-preview', 'Reminders are off.');
        self::assertSelectorTextContains('#reminders-preview', 'No first reminder would go out over the next 30 days.');

        $this->saveTemplates(['reminders' => ['enabled' => '1']]);
        $this->client->request('GET', $this->url());

        self::assertSelectorTextContains('#reminders-preview h4', 'Reminded tomorrow');
        self::assertSelectorTextContains('#reminders-preview', 'No reminder goes out tomorrow.');
    }

    public function testThePreviewRowsNameTheInvoiceAndItsDates(): void
    {
        $this->browser->seed();
        $this->browser->signIn();
        $invoices = $this->firmWithDeliveredInvoices(['2026-11-10', '2026-10-20']);

        // Off: what switching on today would send, with the day of each first reminder.
        $crawler = $this->client->request('GET', $this->url());

        $rows = $crawler->filter('#reminders-preview li');
        self::assertCount(1, $rows, 'due before today, so not reminded once switched on today');
        self::assertSame('/invoices/'.$invoices[0]->id(), $rows->filter('a')->attr('href'));
        self::assertStringContainsString('20260001', $rows->text());
        self::assertStringContainsString('Example Customer a.s.', $rows->text());
        self::assertStringContainsString('reminder on 17 Nov 2026', $rows->text());
        self::assertStringContainsString('due 10 Nov 2026', $rows->text());
        self::assertStringContainsString('1,210.00', $rows->text());

        // On since October: tomorrow's reminders, dated by their due day.
        $this->saveTemplates(['reminders' => ['enabled' => '1']]);
        $this->connection()->executeStatement("UPDATE firm_reminders SET enabled_on = '2026-10-01'");
        $crawler = $this->client->request('GET', $this->url());

        $rows = $crawler->filter('#reminders-preview li');
        self::assertCount(1, $rows);
        self::assertStringContainsString('20260002', $rows->text());
        self::assertStringContainsString('due 20 Oct 2026', $rows->text());
        self::assertStringNotContainsString('reminder on', $rows->text());
    }

    public function testThePreviewLeavesOutWhatIsPaidAndShowsWhatIsLeft(): void
    {
        $this->browser->seed();
        $this->browser->signIn();
        $invoices = $this->firmWithDeliveredInvoices(['2026-11-10', '2026-11-11', '2026-10-20', '2026-10-21']);
        [$partly, $paid, $partlyOverdue, $paidOverdue] = $invoices;
        $this->pay($partly, '210.00');
        $this->pay($paid, '1210.00');
        $this->pay($partlyOverdue, '1000.00');
        $this->pay($paidOverdue, '1210.00');

        // Off: the plan of switching on today.
        $rows = $this->client->request('GET', $this->url())->filter('#reminders-preview li');

        self::assertCount(1, $rows, 'the paid one is never planned');
        self::assertStringContainsString($partly->number(), $rows->text());
        self::assertStringContainsString('1,000.00', $rows->text(), 'what is left, not the total');
        self::assertStringNotContainsString('1,210.00', $rows->text());

        // On since October: tomorrow's reminders.
        $this->saveTemplates(['reminders' => ['enabled' => '1']]);
        $this->connection()->executeStatement("UPDATE firm_reminders SET enabled_on = '2026-10-01'");
        $rows = $this->client->request('GET', $this->url())->filter('#reminders-preview li');

        self::assertCount(1, $rows, 'the paid one is never reminded');
        self::assertStringContainsString($partlyOverdue->number(), $rows->text());
        self::assertStringContainsString('210.00', $rows->text());
        self::assertStringNotContainsString('1,210.00', $rows->text());
    }

    public function testRecordedPaymentsLiftTheGate(): void
    {
        $this->signedInFirm();
        $container = self::getContainer();
        // The bound balances decide.
        $container->set(ReminderSettings::class, new DoctrineReminderSettings($container->get('doctrine.orm.entity_manager'), $container->get(InvoiceBalances::class)));

        $crawler = $this->client->request('GET', $this->url());
        self::assertCount(0, $crawler->filter('#reminders-enabled[disabled]'));
        self::assertSelectorTextNotContains('#reminders-card', 'once payments are recorded');

        $this->client->request('POST', $this->url(), $this->formValues(['enabled' => '1']));

        self::assertResponseRedirects($this->url());
        self::assertEquals(1, $this->connection()->fetchOne('SELECT enabled FROM firm_reminders'));
    }

    public function testSwitchingOnRecordsTheDay(): void
    {
        $this->signedInFirm();

        $this->saveTemplates(['reminders' => ['enabled' => '1']]);
        Clock::set(new MockClock('2026-11-10 12:00:00'));
        $this->saveTemplates(['reminders' => ['everyDays' => '9']]);
        self::assertSame('2026-11-02', $this->connection()->fetchOne('SELECT enabled_on FROM firm_reminders'), 'saving again keeps the day it was switched on');

        // An unchecked box posts nothing.
        $this->client->request('GET', $this->url());
        $this->client->request('POST', $this->url(), $this->formValues(['enabled' => null]));
        self::assertResponseRedirects($this->url());
        self::assertEquals(['enabled' => 0, 'enabled_on' => '2026-11-02'], $this->connection()->fetchAssociative('SELECT enabled, enabled_on FROM firm_reminders'));

        Clock::set(new MockClock('2026-11-12 23:30:00'));
        $this->saveTemplates(['reminders' => ['enabled' => '1']]);
        self::assertSame('2026-11-13', $this->connection()->fetchOne('SELECT enabled_on FROM firm_reminders'), 'the day of the firm, in Prague already the 13th');
    }

    public function testTheGateRefusesSwitchingOnWithoutPayments(): void
    {
        $this->signedInFirm();
        $container = self::getContainer();
        $container->set(ReminderSettings::class, new DoctrineReminderSettings($container->get('doctrine.orm.entity_manager'), new UnauthoritativeBalances($container->get(InvoiceBalances::class))));

        $crawler = $this->client->request('GET', $this->url());
        self::assertCount(1, $crawler->filter('#reminders-enabled[disabled]'));
        self::assertSelectorTextContains('#reminders-card', 'Automatic reminders start once payments are recorded');

        $this->client->request('POST', $this->url(), $this->formValues(['enabled' => '1']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#reminders-enabled-error', 'once payments are recorded');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_reminders'));

        // The rest of the card still saves while the switch stays off.
        $this->client->request('POST', $this->url(), $this->formValues(['enabled' => null, 'firstAfterDays' => '14']));
        self::assertResponseRedirects($this->url());
        self::assertEquals(['enabled' => 0, 'enabled_on' => null, 'first_after_days' => 14], $this->connection()->fetchAssociative('SELECT enabled, enabled_on, first_after_days FROM firm_reminders'));
    }

    public function testDaysOutsideOneTo365AreRefused(): void
    {
        $this->signedInFirm();

        $this->saveTemplates(['reminders' => ['enabled' => '1', 'firstAfterDays' => '0', 'everyDays' => '366', 'minimum' => '-5']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#reminders-first-error', 'from 1 to 365');
        self::assertSelectorTextContains('#reminders-every-error', 'from 1 to 365');
        self::assertSelectorExists('#reminders-minimum-error');
        self::assertSame('0', $this->client->getCrawler()->filter('#reminders-first')->attr('value'), 'what was typed is shown again');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_reminders'));
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    private function boot(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    private function signedInFirm(string $country = 'CZ'): LegalEntity
    {
        $this->browser->seed();
        $this->browser->signIn();

        return $this->firm($country);
    }

    private function firm(string $country = 'CZ'): LegalEntity
    {
        $number = 'CZ' === $country ? new RegistrationNumber('CZ', '12345695') : new RegistrationNumber($country, 'HRB 12345');
        $firm = new LegalEntity('Example Firm s.r.o.', $number, null, new Address('Example Street 1', 'Example City', '10000', $country), VatStatus::NotRegistered, '');
        $em = $this->browser->em();
        $em->persist($firm);
        $em->flush();

        return $firm;
    }

    /**
     * A payer firm with a customer reminded by e-mail, and a delivered invoice per due date, issued on 1 October.
     *
     * @param list<string> $dueDates
     *
     * @return list<Invoice>
     */
    private function firmWithDeliveredInvoices(array $dueDates): array
    {
        $em = $this->browser->em();
        $iban = 'CZ6508000000192000145399';
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1'));
        FirmAccounts::add($em, $iban);
        $series = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $series->markDefault();
        $em->persist($series);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $em->persist($customer);
        $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $em->persist(new PartyEmail($customer, 'billing@example.com', EmailPurpose::Invoices));
        $em->flush();
        $day = new \DateTimeImmutable('2026-10-01');
        $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
        $invoices = [];
        foreach ($dueDates as $due) {
            $invoice = self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput($series->id(), null, $customer->id(), null, $day, $day, new \DateTimeImmutable($due), PaymentMethod::BankTransfer, $iban, '', '', '', 'Consulting', '', '', DocumentLanguage::Czech, [$line]));
            self::getContainer()->get(MarkDelivered::class)->mark($invoice->id());
            $invoices[] = $invoice;
        }

        return $invoices;
    }

    private function pay(Invoice $invoice, string $amount): void
    {
        $czk = Money::of($amount, Currency::CZK);
        self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest(new \DateTimeImmutable('2026-11-01'), $czk, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoice->id(), $czk)]));
    }

    private function url(?string $tab = null): string
    {
        return '/settings/firm/mail'.(null === $tab ? '' : '?tab='.$tab);
    }

    private function templateUrl(string $kind): string
    {
        return '/settings/firm/mail/templates/'.$kind;
    }

    private function previewToken(): string
    {
        $crawler = $this->client->request('GET', $this->templateUrl('invoice'));
        $token = $crawler->filter('[data-controller="markdown-editor"]')->first()->attr('data-markdown-editor-token-value');
        self::assertIsString($token);

        return $token;
    }

    /**
     * Posts the sheet of one kind of template, the kind read from the field names
     * (reminder_subject is the reminder's), or the reminders card for "reminders".
     *
     * @param array<string, mixed> $values
     */
    private function saveTemplates(array $values, ?string $kind = null): void
    {
        if (isset($values['reminders'])) {
            $crawler = $this->client->request('GET', $this->url());
            $this->client->request('POST', $this->url(), array_replace_recursive($crawler->filter('#mail-reminders')->form()->getPhpValues(), $values));

            return;
        }
        foreach (array_keys($values) as $name) {
            if (1 === preg_match('/^(.+)_(?:subject|body)$/', $name, $match)) {
                $kind ??= $match[1];
            }
        }
        $url = $this->templateUrl($kind ?? 'invoice');
        $crawler = $this->client->request('GET', $url);
        $this->client->request('POST', $url, array_replace_recursive($crawler->filter('#mail-template')->form()->getPhpValues(), $values));
    }

    /**
     * The reminders form of the page on screen, with fields of the reminders card
     * replaced; null leaves a field out, as an unchecked box does.
     *
     * @param array<string, string|null> $reminders
     *
     * @return array<mixed>
     */
    private function formValues(array $reminders): array
    {
        $values = $this->client->getCrawler()->filter('#mail-reminders')->form()->getPhpValues();
        $card = $values['reminders'] ?? [];
        self::assertIsArray($card);
        foreach ($reminders as $field => $value) {
            if (null === $value) {
                unset($card[$field]);
            } else {
                $card[$field] = $value;
            }
        }
        $values['reminders'] = $card;

        return $values;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
