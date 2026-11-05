<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Identity\Domain\Role;
use App\Invoicing\Application\AllocationRequest;
use App\Invoicing\Application\InvoiceBalances;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\MarkDelivered;
use App\Invoicing\Application\PaymentRequest;
use App\Invoicing\Application\RecordPayment;
use App\Invoicing\Application\ReminderSettings;
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
use App\Invoicing\Infrastructure\Doctrine\DoctrineReminderSettings;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\EmailPurpose;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyEmail;
use App\Party\Domain\PartyKind;
use App\Party\Domain\PaymentMethod;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Tests\Invoicing\Infrastructure\Support\UnauthoritativeBalances;
use App\Tests\Party\Support\FirmAccounts;
use App\Tests\Support\Mail\RecordingMailGateway;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class FirmMailControllerTest extends WebTestCase
{
    private const string PASSWORD = 'correct-horse-battery';
    private const array SERVER = [
        'host' => 'smtp.example.com',
        'port' => '587',
        'encryption' => 'starttls',
        'username' => 'invoices@example.com',
        'password' => self::PASSWORD,
        'fromAddress' => 'invoices@example.com',
        'fromName' => 'Example Firm',
    ];

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private TenantContext $context;
    private ?string $keys = null;

    protected function setUp(): void
    {
        $this->boot();
        RecordingMailGateway::reset();
        Clock::set(new MockClock('2026-11-02 12:00:00'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        if (null !== $this->keys) {
            $_SERVER['MAIL_SECRET_KEYS'] = $_ENV['MAIL_SECRET_KEYS'] = $this->keys;
        }
        RecordingMailGateway::reset();
        parent::tearDown();
    }

    public function testTheAdvanceAndTaxDocumentTemplatesAreEditedLikeTheInvoice(): void
    {
        $firm = $this->signedInFirm();
        $crawler = $this->client->request('GET', $this->url($firm));
        self::assertSame('Zálohová faktura {Číslo dokladu}', $crawler->filter('#advance-subject-cs')->attr('value'));
        self::assertSame('Tax document {Document number}', $crawler->filter('#tax_document-subject-en')->attr('value'));

        $this->saveTemplates($firm, ['advance_subject' => ['cs' => 'Záloha {Číslo dokladu}'], 'advance_body' => ['cs' => "Záloha {Částka k úhradě}.\n{Odkaz na doklad}"]]);

        self::assertResponseRedirects($this->url($firm));
        self::assertSame(
            [['kind' => 'advance', 'language' => 'cs', 'subject' => 'Záloha {number}', 'body' => "Záloha {amount_total}.\n{link}"]],
            $this->connection()->fetchAllAssociative('SELECT kind, language, subject, body FROM mail_template'),
        );
        $this->client->request('GET', $this->url($firm));
        self::assertSelectorExists('form#mail-reset-advance-cs input[name="kind"][value="advance"]');
    }

    public function testTheCorrectionTemplateKnowsTheNumberOfTheOriginal(): void
    {
        $firm = $this->signedInFirm();
        $crawler = $this->client->request('GET', $this->url($firm));
        self::assertSame('Opravný daňový doklad {Číslo dokladu}', $crawler->filter('#correction-subject-cs')->attr('value'));
        self::assertStringContainsString('{Number of the corrected document}', $crawler->filter('#correction-body-en')->text());

        $this->saveTemplates($firm, ['correction_body' => ['cs' => "Oprava dokladu {Číslo opravovaného dokladu}.\n{Odkaz na doklad}"]]);

        self::assertResponseRedirects($this->url($firm));
        self::assertSame(
            [['kind' => 'correction', 'language' => 'cs', 'body' => "Oprava dokladu {original_number}.\n{link}"]],
            $this->connection()->fetchAllAssociative('SELECT kind, language, body FROM mail_template'),
        );
    }

    public function testThePageHasBothTabsAndTheChips(): void
    {
        $firm = $this->signedInFirm();

        $crawler = $this->client->request('GET', $this->url($firm));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main [data-action-bar]');
        self::assertCount(2, $crawler->filter('main nav[aria-label="E-mail settings sections"] a'));
        self::assertSame('page', $crawler->filter('main nav[aria-label="Firm sections"] a[href$="/mail"]')->attr('aria-current'));
        // The editor shows each token as a label in the template's own language.
        self::assertSame('Invoice {Document number}', $crawler->filter('#subject-en')->attr('value'));
        self::assertSame('Faktura {Číslo dokladu}', $crawler->filter('#subject-cs')->attr('value'));
        self::assertStringContainsString('for {Amount to pay}, due on {Due date}.', $crawler->filter('#body-en')->text(null, false));
        self::assertStringContainsString('na částku {Částka k úhradě} se splatností {Datum splatnosti}.', $crawler->filter('#body-cs')->text());
        self::assertStringNotContainsString('{amount_total}', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('#body-cs-help', '{Odkaz na doklad}');
        self::assertSelectorTextContains('#body-en-help', '{Link to the document}');
        // One toolbar per language, named with it, and one Tab stop in each.
        self::assertSame(
            [
                'variables-cs language-cs', 'variables-en language-en',
                'variables-advance-cs kind-advance-cs language-advance-cs', 'variables-advance-en kind-advance-en language-advance-en',
                'variables-tax_document-cs kind-tax_document-cs language-tax_document-cs', 'variables-tax_document-en kind-tax_document-en language-tax_document-en',
                'variables-correction-cs kind-correction-cs language-correction-cs', 'variables-correction-en kind-correction-en language-correction-en',
                'variables-reminder-cs kind-reminder-cs language-reminder-cs', 'variables-reminder-en kind-reminder-en language-reminder-en',
            ],
            $crawler->filter('[role="toolbar"]')->each(static fn ($toolbar): string => (string) $toolbar->attr('aria-labelledby')),
        );
        self::assertSame(array_fill(0, 10, '0'), $crawler->filter('[role="toolbar"] button[tabindex="0"]')->each(static fn ($chip): string => (string) $chip->attr('tabindex')));
        $chips = $crawler->filter('[role="toolbar"][aria-labelledby] button[type="button"][data-token]');
        self::assertSame(['{Číslo dokladu}', '{Odkaz na doklad}', '{Document number}'], [$chips->first()->attr('data-token'), $chips->eq(19)->attr('data-token'), $chips->eq(20)->attr('data-token')]);
    }

    public function testSavingStoresServerAndTemplate(): void
    {
        $firm = $this->signedInFirm();

        $this->saveServer($firm, self::SERVER);
        self::assertResponseRedirects($this->url($firm, 'server'));
        $this->saveTemplates($firm, ['subject' => ['cs' => 'Faktura {number} od nás'], 'body' => ['cs' => "Dobrý den,\n{link}"]]);
        self::assertResponseRedirects($this->url($firm));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The e-mail templates are saved.');

        $server = $this->connection()->fetchAssociative('SELECT legal_entity_id, host, port, encryption, username, from_address, from_name, verified_at FROM firm_mail_server');
        self::assertSame(['legal_entity_id' => $firm->id(), 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'invoices@example.com', 'from_address' => 'invoices@example.com', 'from_name' => 'Example Firm', 'verified_at' => null], $server);
        self::assertSame(self::PASSWORD, $this->storedPassword());
        // The English text was left as built in, so only the Czech one is stored.
        self::assertSame([['language' => 'cs', 'subject' => 'Faktura {number} od nás', 'body' => "Dobrý den,\n{link}"]], $this->connection()->fetchAllAssociative('SELECT language, subject, body FROM mail_template'));
    }

    public function testLabelsAreStoredAsStableNamesAndShownAgain(): void
    {
        $firm = $this->signedInFirm();

        $this->saveTemplates($firm, [
            'subject' => ['cs' => 'Faktura {číslo  dokladu} pro {Název odběratele}', 'en' => 'Invoice {Document number}'],
            'body' => ['cs' => "Splatnost {Datum splatnosti}.\n{Odkaz na doklad}", 'en' => "Due {due_date}.\n{Link to the document}"],
        ]);

        self::assertResponseRedirects($this->url($firm));
        self::assertSame(['cs' => 'Faktura {number} pro {customer_name}', 'en' => 'Invoice {number}'], $this->connection()->fetchAllKeyValue('SELECT language, subject FROM mail_template ORDER BY language'));
        self::assertSame(['cs' => "Splatnost {due_date}.\n{link}", 'en' => "Due {due_date}.\n{link}"], $this->connection()->fetchAllKeyValue('SELECT language, body FROM mail_template ORDER BY language'));
        $crawler = $this->client->request('GET', $this->url($firm));
        self::assertSame('Faktura {Číslo dokladu} pro {Název odběratele}', $crawler->filter('#subject-cs')->attr('value'));
        self::assertSame("Due {Due date}.\n{Link to the document}", $crawler->filter('#body-en')->text(null, false));
    }

    public function testAVeryLongTokenIsRefusedWithAMessage(): void
    {
        $firm = $this->signedInFirm();

        $this->saveTemplates($firm, ['subject' => ['cs' => 'Faktura {'.str_repeat('a', 120).'}'], 'body' => ['cs' => '{'.str_repeat('b', 300).'} {Odkaz na doklad}']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#subject-cs-error', '{aaaa');
        self::assertSelectorTextContains('#body-cs-error', '…');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testAnUnknownStableNameIsRefusedNotA500(): void
    {
        $firm = $this->signedInFirm();

        $this->saveTemplates($firm, ['subject' => ['en' => 'Invoice {number_'.str_repeat('x', 90).'}']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#subject-en-error');
    }

    public function testALabelOfTheOtherLanguageIsUnknown(): void
    {
        $firm = $this->signedInFirm();

        $this->saveTemplates($firm, ['subject' => ['en' => 'Invoice {Číslo dokladu}']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#subject-en-error', 'Unknown variable: {Číslo dokladu}.');
        self::assertSame('Invoice {Číslo dokladu}', $this->client->getCrawler()->filter('#subject-en')->attr('value'));
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testResetGivesTheBuiltInTextBack(): void
    {
        $firm = $this->signedInFirm();
        $this->saveTemplates($firm, ['subject' => ['en' => 'Own {number}']]);

        $this->saveTemplates($firm, ['reset' => 'en']);

        self::assertResponseRedirects($this->url($firm));
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testReminderSettingsAreStored(): void
    {
        $firm = $this->signedInFirm();
        $crawler = $this->client->request('GET', $this->url($firm));
        self::assertCount(0, $crawler->filter('#reminders-enabled[checked]'), 'off until the owner switches it on');
        self::assertSame(['7', '7', ''], [$crawler->filter('#reminders-first')->attr('value'), $crawler->filter('#reminders-every')->attr('value'), $crawler->filter('#reminders-minimum')->attr('value')]);
        self::assertSelectorTextContains('#reminders-card', 'Up to 10 reminders');

        $this->saveTemplates($firm, ['reminders' => ['enabled' => '1', 'firstAfterDays' => '10', 'everyDays' => '5', 'minimum' => '500.50']]);

        self::assertResponseRedirects($this->url($firm));
        self::assertEquals(
            ['legal_entity_id' => $firm->id(), 'enabled' => 1, 'enabled_on' => '2026-11-02', 'first_after_days' => 10, 'every_days' => 5, 'minimum_minor' => 50050, 'minimum_currency' => 'CZK'],
            $this->connection()->fetchAssociative('SELECT legal_entity_id, enabled, enabled_on, first_after_days, every_days, minimum_minor, minimum_currency FROM firm_reminders'),
        );
        $crawler = $this->client->request('GET', $this->url($firm));
        self::assertCount(1, $crawler->filter('#reminders-enabled[checked]'));
        self::assertSame(['10', '5', '500.50'], [$crawler->filter('#reminders-first')->attr('value'), $crawler->filter('#reminders-every')->attr('value'), $crawler->filter('#reminders-minimum')->attr('value')]);

        // A blank minimum is no minimum.
        $this->saveTemplates($firm, ['reminders' => ['minimum' => '']]);
        self::assertResponseRedirects($this->url($firm));
        self::assertSame(['minimum_minor' => null, 'minimum_currency' => null], $this->connection()->fetchAssociative('SELECT minimum_minor, minimum_currency FROM firm_reminders'));
    }

    public function testAdvancesAreRemindedOnlyWhenSwitchedOn(): void
    {
        $firm = $this->signedInFirm();
        $crawler = $this->client->request('GET', $this->url($firm));
        self::assertCount(0, $crawler->filter('#reminders-advances[checked]'));

        $this->saveTemplates($firm, ['reminders' => ['advances' => '1']]);

        self::assertResponseRedirects($this->url($firm));
        self::assertEquals(['remind_advances' => 1, 'enabled' => 0], $this->connection()->fetchAssociative('SELECT remind_advances, enabled FROM firm_reminders'));
        $crawler = $this->client->request('GET', $this->url($firm));
        self::assertCount(1, $crawler->filter('#reminders-advances[checked]'));

        $form = $crawler->filter('#mail-templates')->form()->getPhpValues();
        self::assertIsArray($form['reminders']);
        unset($form['reminders']['advances']);
        $this->client->request('POST', $this->url($firm), $form);
        self::assertEquals(['remind_advances' => 0], $this->connection()->fetchAssociative('SELECT remind_advances FROM firm_reminders'));
    }

    public function testAFirmInACountryWithoutInvoicingRulesHasNoRemindersCard(): void
    {
        $firm = $this->signedInFirm('DE');

        $crawler = $this->client->request('GET', $this->url($firm));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#reminders-card'));
        self::assertCount(1, $crawler->filter('#subject-cs'));

        $this->saveTemplates($firm, ['subject' => ['cs' => 'Faktura {number} od nás']]);

        self::assertResponseRedirects($this->url($firm));
        self::assertSame(['Faktura {number} od nás'], $this->connection()->fetchFirstColumn('SELECT subject FROM mail_template'));
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT legal_entity_id FROM firm_reminders'));
    }

    public function testThePreviewSaysWhatSwitchingOnTodayWouldSendWhileOff(): void
    {
        $firm = $this->signedInFirm();

        $this->client->request('GET', $this->url($firm));

        self::assertSelectorTextContains('#reminders-preview h4', 'If you switched reminders on today');
        self::assertSelectorTextContains('#reminders-preview', 'Reminders are off.');
        self::assertSelectorTextContains('#reminders-preview', 'No first reminder would go out over the next 30 days.');

        $this->saveTemplates($firm, ['reminders' => ['enabled' => '1']]);
        $this->client->request('GET', $this->url($firm));

        self::assertSelectorTextContains('#reminders-preview h4', 'Reminded tomorrow');
        self::assertSelectorTextContains('#reminders-preview', 'No reminder goes out tomorrow.');
    }

    public function testThePreviewRowsNameTheInvoiceAndItsDates(): void
    {
        [$tenant] = $this->browser->seed(['Tenant A']);
        $this->browser->signIn();
        [$firm, $invoices] = $this->firmWithDeliveredInvoices($tenant, ['2026-11-10', '2026-10-20']);

        // Off: what switching on today would send, with the day of each first reminder.
        $crawler = $this->client->request('GET', $this->url($firm));

        $rows = $crawler->filter('#reminders-preview li');
        self::assertCount(1, $rows, 'due before today, so not reminded once switched on today');
        self::assertSame('/invoices/'.$invoices[0]->id().'/edit', $rows->filter('a')->attr('href'));
        self::assertStringContainsString('20260001', $rows->text());
        self::assertStringContainsString('Example Customer a.s.', $rows->text());
        self::assertStringContainsString('reminder on 17 Nov 2026', $rows->text());
        self::assertStringContainsString('due 10 Nov 2026', $rows->text());
        self::assertStringContainsString('1,210.00', $rows->text());

        // On since October: tomorrow's reminders, dated by their due day.
        $this->saveTemplates($firm, ['reminders' => ['enabled' => '1']]);
        $this->connection()->executeStatement("UPDATE firm_reminders SET enabled_on = '2026-10-01'");
        $crawler = $this->client->request('GET', $this->url($firm));

        $rows = $crawler->filter('#reminders-preview li');
        self::assertCount(1, $rows);
        self::assertStringContainsString('20260002', $rows->text());
        self::assertStringContainsString('due 20 Oct 2026', $rows->text());
        self::assertStringNotContainsString('reminder on', $rows->text());
    }

    public function testThePreviewLeavesOutWhatIsPaidAndShowsWhatIsLeft(): void
    {
        [$tenant] = $this->browser->seed(['Tenant A']);
        $this->browser->signIn();
        [$firm, $invoices] = $this->firmWithDeliveredInvoices($tenant, ['2026-11-10', '2026-11-11', '2026-10-20', '2026-10-21']);
        [$partly, $paid, $partlyOverdue, $paidOverdue] = $invoices;
        $this->pay($firm, $partly, '210.00');
        $this->pay($firm, $paid, '1210.00');
        $this->pay($firm, $partlyOverdue, '1000.00');
        $this->pay($firm, $paidOverdue, '1210.00');

        // Off: the plan of switching on today.
        $rows = $this->client->request('GET', $this->url($firm))->filter('#reminders-preview li');

        self::assertCount(1, $rows, 'the paid one is never planned');
        self::assertStringContainsString($partly->number(), $rows->text());
        self::assertStringContainsString('1,000.00', $rows->text(), 'what is left, not the total');
        self::assertStringNotContainsString('1,210.00', $rows->text());

        // On since October: tomorrow's reminders.
        $this->saveTemplates($firm, ['reminders' => ['enabled' => '1']]);
        $this->connection()->executeStatement("UPDATE firm_reminders SET enabled_on = '2026-10-01'");
        $rows = $this->client->request('GET', $this->url($firm))->filter('#reminders-preview li');

        self::assertCount(1, $rows, 'the paid one is never reminded');
        self::assertStringContainsString($partlyOverdue->number(), $rows->text());
        self::assertStringContainsString('210.00', $rows->text());
        self::assertStringNotContainsString('1,210.00', $rows->text());
    }

    public function testRecordedPaymentsLiftTheGate(): void
    {
        $firm = $this->signedInFirm();
        $container = self::getContainer();
        // The bound balances decide.
        $container->set(ReminderSettings::class, new DoctrineReminderSettings($container->get('doctrine.orm.entity_manager'), $container->get(InvoiceBalances::class)));

        $crawler = $this->client->request('GET', $this->url($firm));
        self::assertCount(0, $crawler->filter('#reminders-enabled[disabled]'));
        self::assertSelectorTextNotContains('#reminders-card', 'once payments are recorded');

        $this->client->request('POST', $this->url($firm), $this->formValues(['enabled' => '1']));

        self::assertResponseRedirects($this->url($firm));
        self::assertEquals(1, $this->connection()->fetchOne('SELECT enabled FROM firm_reminders'));
    }

    public function testSwitchingOnRecordsTheDay(): void
    {
        $firm = $this->signedInFirm();

        $this->saveTemplates($firm, ['reminders' => ['enabled' => '1']]);
        Clock::set(new MockClock('2026-11-10 12:00:00'));
        $this->saveTemplates($firm, ['reminders' => ['everyDays' => '9']]);
        self::assertSame('2026-11-02', $this->connection()->fetchOne('SELECT enabled_on FROM firm_reminders'), 'saving again keeps the day it was switched on');

        // An unchecked box posts nothing.
        $this->client->request('GET', $this->url($firm));
        $this->client->request('POST', $this->url($firm), $this->formValues(['enabled' => null]));
        self::assertResponseRedirects($this->url($firm));
        self::assertEquals(['enabled' => 0, 'enabled_on' => '2026-11-02'], $this->connection()->fetchAssociative('SELECT enabled, enabled_on FROM firm_reminders'));

        Clock::set(new MockClock('2026-11-12 23:30:00'));
        $this->saveTemplates($firm, ['reminders' => ['enabled' => '1']]);
        self::assertSame('2026-11-13', $this->connection()->fetchOne('SELECT enabled_on FROM firm_reminders'), 'the day of the firm, in Prague already the 13th');
    }

    public function testTheGateRefusesSwitchingOnWithoutPayments(): void
    {
        $firm = $this->signedInFirm();
        $container = self::getContainer();
        $container->set(ReminderSettings::class, new DoctrineReminderSettings($container->get('doctrine.orm.entity_manager'), new UnauthoritativeBalances($container->get(InvoiceBalances::class))));

        $crawler = $this->client->request('GET', $this->url($firm));
        self::assertCount(1, $crawler->filter('#reminders-enabled[disabled]'));
        self::assertSelectorTextContains('#reminders-card', 'Automatic reminders start once payments are recorded');

        $this->client->request('POST', $this->url($firm), $this->formValues(['enabled' => '1']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#reminders-enabled-error', 'once payments are recorded');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_reminders'));

        // The rest of the card still saves while the switch stays off.
        $this->client->request('POST', $this->url($firm), $this->formValues(['enabled' => null, 'firstAfterDays' => '14']));
        self::assertResponseRedirects($this->url($firm));
        self::assertEquals(['enabled' => 0, 'enabled_on' => null, 'first_after_days' => 14], $this->connection()->fetchAssociative('SELECT enabled, enabled_on, first_after_days FROM firm_reminders'));
    }

    public function testDaysOutsideOneTo365AreRefused(): void
    {
        $firm = $this->signedInFirm();

        $this->saveTemplates($firm, ['reminders' => ['enabled' => '1', 'firstAfterDays' => '0', 'everyDays' => '366', 'minimum' => '-5']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#reminders-first-error', 'from 1 to 365');
        self::assertSelectorTextContains('#reminders-every-error', 'from 1 to 365');
        self::assertSelectorExists('#reminders-minimum-error');
        self::assertSame('0', $this->client->getCrawler()->filter('#reminders-first')->attr('value'), 'what was typed is shown again');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_reminders'));
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testAReminderTemplateIsStoredPerLanguage(): void
    {
        $firm = $this->signedInFirm();
        $crawler = $this->client->request('GET', $this->url($firm));
        self::assertSame('Upomínka: faktura {Číslo dokladu}', $crawler->filter('#reminder-subject-cs')->attr('value'));
        self::assertStringContainsString('{Zbývá uhradit}', $crawler->filter('#reminder-body-cs')->text(null, false));
        self::assertStringContainsString('{Amount due}', $crawler->filter('#reminder-body-en')->text(null, false));
        $tokens = $crawler->filter('[role="toolbar"][aria-labelledby~="variables-reminder-cs"] button[data-token]')->each(static fn ($chip): string => (string) $chip->attr('data-token'));
        self::assertSame(['{Dní po splatnosti}', '{Pořadí upomínky}', '{Zbývá uhradit}', '{Odkaz na doklad}'], \array_slice($tokens, -4), 'the reminder chips come before the link');

        $this->saveTemplates($firm, ['reminder_subject' => ['cs' => 'Upomínka {Číslo dokladu}'], 'reminder_body' => ['cs' => "Faktura je {Dní po splatnosti} dní po splatnosti, zbývá {Zbývá uhradit}.\n{Odkaz na doklad}"]]);

        self::assertResponseRedirects($this->url($firm));
        self::assertSame(
            [['kind' => 'reminder', 'language' => 'cs', 'subject' => 'Upomínka {number}', 'body' => "Faktura je {days_overdue} dní po splatnosti, zbývá {amount_due}.\n{link}"]],
            $this->connection()->fetchAllAssociative('SELECT kind, language, subject, body FROM mail_template'),
            'only the changed reminder text is stored; the invoice texts stay built in',
        );

        // A reminder variable in the invoice template is unknown there.
        $this->saveTemplates($firm, ['subject' => ['cs' => 'Faktura {Dní po splatnosti}']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#subject-cs-error', '{Dní po splatnosti}');

        // Each kind has its own reset.
        $this->client->request('GET', $this->url($firm));
        self::assertSelectorNotExists('form#mail-reset-cs');
        $reset = $this->client->getCrawler()->filter('form#mail-reset-reminder-cs');
        self::assertCount(1, $reset);
        $this->client->request('POST', $this->url($firm), $reset->form()->getPhpValues());
        self::assertResponseRedirects($this->url($firm));
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testBlankPasswordKeepsTheStoredOne(): void
    {
        $firm = $this->signedInFirm();
        $this->saveServer($firm, self::SERVER);

        $this->saveServer($firm, ['fromName' => 'Example Billing', 'password' => ''] + self::SERVER);

        self::assertResponseRedirects($this->url($firm, 'server'));
        self::assertSame('Example Billing', $this->connection()->fetchOne('SELECT from_name FROM firm_mail_server'));
        self::assertSame(self::PASSWORD, $this->storedPassword());
    }

    public function testABlankPasswordIsRefusedWhenTheConnectionChanges(): void
    {
        $firm = $this->signedInFirm();
        $this->saveServer($firm, self::SERVER);
        $row = $this->connection()->fetchAssociative('SELECT * FROM firm_mail_server');

        foreach ([['host' => 'mail.example.com'], ['port' => '465'], ['encryption' => 'tls'], ['username' => 'other@example.com']] as $change) {
            $this->saveServer($firm, $change + ['password' => ''] + self::SERVER);

            self::assertResponseStatusCodeSame(422, array_key_first($change));
            self::assertSelectorTextContains('#password-error', 'Enter the password again', array_key_first($change));
            self::assertSame($row, $this->connection()->fetchAssociative('SELECT * FROM firm_mail_server'));
            self::assertSame(self::PASSWORD, $this->storedPassword());
        }
    }

    public function testAFirstServerNeedsAPassword(): void
    {
        $firm = $this->signedInFirm();

        $this->saveServer($firm, ['password' => ''] + self::SERVER);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#password-error');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_mail_server'));
    }

    public function testAPrivateHostIsRefusedAtSave(): void
    {
        $firm = $this->signedInFirm();

        foreach (['internal.example.com', '127.0.0.1', 'smtp.example.com:587'] as $host) {
            $this->saveServer($firm, ['host' => $host] + self::SERVER);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorExists('#host-error');
        }
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_mail_server'));
    }

    public function testThePasswordIsNeverInTheResponse(): void
    {
        $firm = $this->signedInFirm();
        $this->saveServer($firm, ['port' => '25'] + self::SERVER);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#port-error');
        $refused = (string) $this->client->getResponse()->getContent();
        $this->saveServer($firm, self::SERVER);

        $this->client->request('GET', $this->url($firm, 'server'));

        $page = (string) $this->client->getResponse()->getContent();
        self::assertSame('smtp.example.com', $this->client->getCrawler()->filter('#host')->attr('value'));
        self::assertSame('', $this->client->getCrawler()->filter('#password')->attr('value'));
        foreach ([$refused, $page] as $content) {
            self::assertStringNotContainsString(self::PASSWORD, $content);
            self::assertStringNotContainsString($this->sealed(), $content);
        }
    }

    public function testUnknownVariableIsRefusedAndNamed(): void
    {
        $firm = $this->signedInFirm();

        $this->saveTemplates($firm, ['subject' => ['cs' => 'Faktura {numbr}'], 'body' => ['en' => 'Pay {amount} now {link}']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#subject-cs-error', '{numbr}');
        self::assertSelectorTextContains('#body-en-error', '{amount}');
        self::assertSame('Faktura {numbr}', $this->client->getCrawler()->filter('#subject-cs')->attr('value'), 'the typed text stays');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testTheLinkInTheSubjectIsRefused(): void
    {
        $firm = $this->signedInFirm();

        $this->saveTemplates($firm, ['subject' => ['cs' => 'Faktura {link}']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#subject-cs-error', '{Odkaz na doklad}');
    }

    public function testAMissingKeyExplainsItself(): void
    {
        $this->keys = \is_string($_SERVER['MAIL_SECRET_KEYS'] ?? null) ? $_SERVER['MAIL_SECRET_KEYS'] : '';
        $_SERVER['MAIL_SECRET_KEYS'] = $_ENV['MAIL_SECRET_KEYS'] = '';
        self::ensureKernelShutdown();
        $this->boot();
        $firm = $this->signedInFirm();

        $this->client->request('GET', $this->url($firm, 'server'));
        self::assertSelectorTextContains('main [role="alert"], main .alert, main', 'MAIL_SECRET_KEYS');
        $this->saveServer($firm, self::SERVER);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'MAIL_SECRET_KEYS');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_mail_server'));
    }

    public function testAnotherTenantsFirmIs404(): void
    {
        $this->signedInFirm();
        $foreign = $this->firm($this->browser->fixtures->tenant('Foreign Tenant'));

        $this->client->request('GET', $this->url($foreign));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->url($foreign), ['section' => 'server'] + self::SERVER);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->url($foreign).'/test');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAClientRoleGets403(): void
    {
        $tenant = $this->browser->seed(['Tenant A'], role: Role::Client)[0];
        $firm = $this->firm($tenant);
        $this->browser->signIn();

        $this->client->request('GET', $this->url($firm));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url($firm).'/test');
        self::assertResponseStatusCodeSame(403);
    }

    public function testACsrfFailureChangesNothing(): void
    {
        $firm = $this->signedInFirm();

        $this->client->request('POST', $this->url($firm), ['_token' => 'wrong', 'section' => 'server'] + self::SERVER);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_mail_server'));
    }

    public function testTheTestMailGoesToTheUserAndSetsVerifiedAt(): void
    {
        $firm = $this->signedInFirm();
        $this->saveServer($firm, self::SERVER);

        $this->sendTest($firm);

        self::assertResponseRedirects($this->url($firm, 'server'));
        $messages = $this->gateway()->messages();
        self::assertCount(1, $messages);
        self::assertSame([IdentityFixtures::EMAIL], $messages[0]->to);
        self::assertSame('invoices@example.com', $messages[0]->from);
        self::assertMatchesRegularExpression('/^<[^@]+@example\.com>$/', $messages[0]->messageId);
        self::assertSame('2026-11-02 12:00:00', $this->connection()->fetchOne('SELECT verified_at FROM firm_mail_server'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', IdentityFixtures::EMAIL);
    }

    public function testARefusedTestShowsTheSafeReason(): void
    {
        $firm = $this->signedInFirm();
        $this->saveServer($firm, self::SERVER);
        $this->gateway()->failNext(1, true);

        $this->sendTest($firm);
        $this->client->followRedirect();

        self::assertSelectorTextContains('main', 'SMTP 550: the mail server refused the message.');
        self::assertNull($this->connection()->fetchOne('SELECT verified_at FROM firm_mail_server'));
    }

    public function testASettingsProblemClearsTheVerificationAndShowsTheLastResult(): void
    {
        $firm = $this->signedInFirm();
        $this->saveServer($firm, self::SERVER);
        $this->sendTest($firm);
        self::assertNotNull($this->connection()->fetchOne('SELECT verified_at FROM firm_mail_server'));
        Clock::set(new MockClock('2026-11-02 12:30:00'));
        $this->gateway()->failNextAsSettingsProblem();

        $this->sendTest($firm);
        $this->client->followRedirect();

        self::assertNull($this->connection()->fetchOne('SELECT verified_at FROM firm_mail_server'));
        self::assertSame(['last_test_at' => '2026-11-02 12:30:00', 'last_test_error' => 'SMTP 535: the mail server refused the login.'], $this->connection()->fetchAssociative('SELECT last_test_at, last_test_error FROM firm_mail_server'));
        self::assertSelectorTextNotContains('main', 'Verified on');
        self::assertSelectorTextContains('#mail-test-result', 'Last test on 2 Nov 2026 at 12:30 failed: SMTP 535: the mail server refused the login.');
    }

    public function testAPassingTestShowsItsTime(): void
    {
        $firm = $this->signedInFirm();
        $this->saveServer($firm, self::SERVER);

        $this->sendTest($firm);
        $this->client->followRedirect();

        self::assertSelectorTextContains('#mail-test-result', 'Verified on 2 Nov 2026 at 12:00.');
    }

    public function testAHostNotFoundAndAMalformedHostHaveTheirOwnMessages(): void
    {
        $firm = $this->signedInFirm();

        $this->saveServer($firm, ['host' => 'nowhere.invalid'] + self::SERVER);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#host-error', 'No server of this name was found');

        $this->saveServer($firm, ['host' => 'smtp_example.com'] + self::SERVER);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#host-error', 'Enter a host name or an IP address');

        $this->saveServer($firm, ['host' => 'internal.example.com'] + self::SERVER);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#host-error', 'cannot be reached from here');
    }

    public function testResetIsAFormOfItsOwnAndAsksFirst(): void
    {
        $firm = $this->signedInFirm();
        $this->saveTemplates($firm, ['subject' => ['cs' => 'Vlastní {Číslo dokladu}']]);

        $crawler = $this->client->request('GET', $this->url($firm));

        self::assertCount(0, $crawler->filter('#mail-templates button[name="reset"]'), 'no reset button inside the save form');
        $reset = $crawler->filter('form#mail-reset-cs');
        self::assertCount(1, $reset);
        self::assertNotSame('', (string) $reset->attr('data-turbo-confirm'));
        self::assertCount(1, $crawler->filter('button[type="submit"][form="mail-reset-cs"]'));
        $this->client->request('POST', $this->url($firm), $reset->form()->getPhpValues());
        self::assertResponseRedirects($this->url($firm));
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testATestWithoutAServerSendsNothing(): void
    {
        $firm = $this->signedInFirm();

        $this->client->request('GET', $this->url($firm, 'server'));
        self::assertSelectorNotExists('button[form="mail-test"]');
    }

    public function testTheTestIsRateLimited(): void
    {
        $firm = $this->signedInFirm();
        $this->saveServer($firm, self::SERVER);

        for ($i = 0; $i < 6; ++$i) {
            $this->sendTest($firm);
        }
        $this->client->followRedirect();

        self::assertCount(5, $this->gateway()->messages());
        self::assertSelectorTextContains('main', 'Too many test e-mails');
    }

    private function boot(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
    }

    private function signedInFirm(string $country = 'CZ'): LegalEntity
    {
        [$tenant] = $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        return $this->firm($tenant, $country);
    }

    private function firm(TenantId $tenant, string $country = 'CZ'): LegalEntity
    {
        $this->context->switchTo($tenant);
        $number = 'CZ' === $country ? new RegistrationNumber('CZ', '12345695') : new RegistrationNumber($country, 'HRB 12345');
        $firm = new LegalEntity('Example Firm s.r.o.', $number, null, new Address('Example Street 1', 'Example City', '10000', $country), VatStatus::NotRegistered, '');
        $em = $this->browser->em();
        $em->persist($firm);
        $em->flush();
        $this->context->reset();

        return $firm;
    }

    /**
     * A payer firm with a customer reminded by e-mail, and a delivered invoice per due date, issued on 1 October.
     *
     * @param list<string> $dueDates
     *
     * @return array{LegalEntity, list<Invoice>}
     */
    private function firmWithDeliveredInvoices(TenantId $tenant, array $dueDates): array
    {
        $this->context->switchTo($tenant);
        try {
            $em = $this->browser->em();
            $iban = 'CZ6508000000192000145399';
            $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
            $em->persist($firm);
            FirmAccounts::add($em, $firm, $iban);
            $series = new Series($firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
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
                $invoice = self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput($firm->id(), $series->id(), null, $customer->id(), null, $day, $day, new \DateTimeImmutable($due), PaymentMethod::BankTransfer, $iban, '', '', '', 'Consulting', '', '', DocumentLanguage::Czech, [$line]));
                self::getContainer()->get(MarkDelivered::class)->mark($invoice->id());
                $invoices[] = $invoice;
            }

            return [$firm, $invoices];
        } finally {
            $this->context->reset();
        }
    }

    private function pay(LegalEntity $firm, Invoice $invoice, string $amount): void
    {
        $this->context->switchTo($firm->tenantId());
        try {
            $czk = Money::of($amount, Currency::CZK);
            self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest($firm->id(), new \DateTimeImmutable('2026-11-01'), $czk, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoice->id(), $czk)]));
        } finally {
            $this->context->reset();
        }
    }

    private function url(LegalEntity $firm, ?string $tab = null): string
    {
        return '/settings/firms/'.$firm->id().'/mail'.(null === $tab ? '' : '?tab='.$tab);
    }

    /**
     * @param array<string, string> $values
     */
    private function saveServer(LegalEntity $firm, array $values): void
    {
        $crawler = $this->client->request('GET', $this->url($firm, 'server'));
        $form = $crawler->filter('#mail-server')->form();
        $this->client->request('POST', $this->url($firm), $values + $form->getPhpValues());
    }

    /**
     * @param array<string, mixed> $values
     */
    private function saveTemplates(LegalEntity $firm, array $values): void
    {
        $crawler = $this->client->request('GET', $this->url($firm));
        $this->client->request('POST', $this->url($firm), array_replace_recursive($crawler->filter('#mail-templates')->form()->getPhpValues(), $values));
    }

    /**
     * The templates form of the page on screen, with fields of the reminders card
     * replaced; null leaves a field out, as an unchecked box does.
     *
     * @param array<string, string|null> $reminders
     *
     * @return array<mixed>
     */
    private function formValues(array $reminders): array
    {
        $values = $this->client->getCrawler()->filter('#mail-templates')->form()->getPhpValues();
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

    private function sendTest(LegalEntity $firm): void
    {
        $crawler = $this->client->request('GET', $this->url($firm, 'server'));
        $this->client->request('POST', $this->url($firm).'/test', $crawler->filter('#mail-test')->form()->getPhpValues());
    }

    private function storedPassword(): string
    {
        return self::getContainer()->get(MailSecrets::class)->open($this->sealed());
    }

    private function gateway(): RecordingMailGateway
    {
        // Its record is static, so a new instance reads what the container's one kept.
        return new RecordingMailGateway();
    }

    private function sealed(): string
    {
        $sealed = $this->connection()->fetchOne('SELECT secret_sealed FROM firm_mail_server');
        self::assertIsString($sealed);

        return $sealed;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
