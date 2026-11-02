<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Identity\Domain\Role;
use App\Invoicing\Application\InvoiceBalances;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\ReminderSettings;
use App\Invoicing\Infrastructure\Doctrine\DoctrineReminderSettings;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\SignedInBrowser;
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
            ['variables-cs language-cs', 'variables-en language-en', 'variables-reminder-cs kind-reminder-cs language-reminder-cs', 'variables-reminder-en kind-reminder-en language-reminder-en'],
            $crawler->filter('[role="toolbar"]')->each(static fn ($toolbar): string => (string) $toolbar->attr('aria-labelledby')),
        );
        self::assertSame(['0', '0', '0', '0'], $crawler->filter('[role="toolbar"] button[tabindex="0"]')->each(static fn ($chip): string => (string) $chip->attr('tabindex')));
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
        $container->set(ReminderSettings::class, new DoctrineReminderSettings($container->get('doctrine.orm.entity_manager'), $container->get(InvoiceBalances::class), false));

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

        $this->saveServer($firm, ['host' => 'mail.example.com', 'password' => ''] + self::SERVER);

        self::assertResponseRedirects($this->url($firm, 'server'));
        self::assertSame('mail.example.com', $this->connection()->fetchOne('SELECT host FROM firm_mail_server'));
        self::assertSame(self::PASSWORD, $this->storedPassword());
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

    private function signedInFirm(): LegalEntity
    {
        [$tenant] = $this->browser->seed(['Tenant A']);
        $this->browser->signIn();

        return $this->firm($tenant);
    }

    private function firm(TenantId $tenant): LegalEntity
    {
        $this->context->switchTo($tenant);
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '', []);
        $em = $this->browser->em();
        $em->persist($firm);
        $em->flush();
        $this->context->reset();

        return $firm;
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
