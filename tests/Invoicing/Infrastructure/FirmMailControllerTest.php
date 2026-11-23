<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Doctrine\DBAL\Connection;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Application\MailSecrets;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Support\Mail\RecordingMailGateway;
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
        $this->signedInFirm();
        $crawler = $this->client->request('GET', $this->templateUrl('advance'));
        self::assertSame('Zálohová faktura {Číslo dokladu} od {Název firmy}', $crawler->filter('#advance-subject-cs')->attr('value'));
        $crawler = $this->client->request('GET', $this->templateUrl('tax_document'));
        self::assertSame('Tax document {Document number} for your payment', $crawler->filter('#tax-document-subject-en')->attr('value'));

        $this->saveTemplates(['advance_subject' => ['cs' => 'Záloha {Číslo dokladu}'], 'advance_body' => ['cs' => "Záloha {Částka k úhradě}.\n{Odkaz na doklad}"]]);

        self::assertResponseRedirects($this->url());
        self::assertSame(
            [['kind' => 'advance', 'language' => 'cs', 'subject' => 'Záloha {number}', 'body' => "Záloha {amount_total}.\n{link}"]],
            $this->connection()->fetchAllAssociative('SELECT kind, language, subject, body FROM mail_template'),
        );
        $this->client->request('GET', $this->templateUrl('advance'));
        self::assertSelectorExists('form#mail-reset-cs[action$="/templates/advance"]');
        self::assertSelectorNotExists('form#mail-reset-en');
    }

    public function testTheCorrectionTemplateKnowsTheNumberOfTheOriginal(): void
    {
        $this->signedInFirm();
        $crawler = $this->client->request('GET', $this->templateUrl('correction'));
        self::assertSame('Opravný daňový doklad {Číslo dokladu}', $crawler->filter('#correction-subject-cs')->attr('value'));
        self::assertStringContainsString('{Number of the corrected document}', $crawler->filter('#correction-body-en')->text());

        $this->saveTemplates(['correction_body' => ['cs' => "Oprava dokladu {Číslo opravovaného dokladu}.\n{Odkaz na doklad}"]]);

        self::assertResponseRedirects($this->url());
        self::assertSame(
            [['kind' => 'correction', 'language' => 'cs', 'body' => "Oprava dokladu {original_number}.\n{link}"]],
            $this->connection()->fetchAllAssociative('SELECT kind, language, body FROM mail_template'),
        );
    }

    public function testTheSheetHasTheLanguagesTheEditorAndTheChips(): void
    {
        $this->signedInFirm();

        $crawler = $this->client->request('GET', $this->templateUrl('invoice'), server: ['HTTP_X_FORESI_DIALOG' => 'sheet']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('dialog#sheet-page'));
        self::assertSame('Invoice', trim($crawler->filter('dialog#sheet-page h2')->text()));
        self::assertSame(['Czech', 'English'], $crawler->filter('[role="tablist"][data-mail-languages-target] [role="tab"]')->each(static fn ($tab): string => trim($tab->text())));
        self::assertSame('true', $crawler->filter('#language-tab-cs')->attr('aria-selected'));
        self::assertSame('language-cs', $crawler->filter('#language-tab-cs')->attr('aria-controls'));
        // The editor shows each token as a label in the template's own language.
        self::assertSame('Invoice {Document number} from {Firm name}', $crawler->filter('#subject-en')->attr('value'));
        self::assertSame('Faktura {Číslo dokladu} od {Název firmy}', $crawler->filter('#subject-cs')->attr('value'));
        self::assertStringContainsString("- Amount due: **{Amount to pay}**\n- Due date: **{Due date}**", $crawler->filter('#body-en')->text(null, false));
        self::assertStringContainsString('- Částka k úhradě: **{Částka k úhradě}** - Splatnost: **{Datum splatnosti}**', $crawler->filter('#body-cs')->text());
        self::assertStringNotContainsString('{amount_total}', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('#body-cs-help', '{Odkaz na doklad}');
        // The body is a Markdown editor whose preview sends the subject along.
        $editor = $crawler->filter('[data-controller="markdown-editor"]')->first();
        self::assertSame('/settings/firm/mail/templates/invoice/preview/cs', $editor->attr('data-markdown-editor-preview-url-value'));
        self::assertSame('{"subject":"subject-cs"}', $editor->attr('data-markdown-editor-preview-fields-value'));
        // One toolbar per language, named with it, and one Tab stop in each.
        self::assertSame(
            ['variables-cs language-title-cs', 'variables-en language-title-en'],
            $crawler->filter('[role="toolbar"][aria-describedby]')->each(static fn ($toolbar): string => (string) $toolbar->attr('aria-labelledby')),
        );
        $chips = $crawler->filter('[role="toolbar"][aria-describedby] button[type="button"][data-token]');
        self::assertSame(['{Číslo dokladu}', '{Odkaz na doklad}', '{Document number}'], [$chips->first()->attr('data-token'), $chips->eq(19)->attr('data-token'), $chips->eq(20)->attr('data-token')]);
        // Cancel and Save in the footer, Save for the form of both languages.
        self::assertCount(1, $crawler->filter('dialog#sheet-page button[type="submit"][form="mail-template"]'));
        self::assertCount(0, $crawler->filter('form[id^="mail-reset-"]'), 'nothing to restore while both texts are built in');
    }

    public function testTheSheetShowsTheLanguageWithAProblemFirst(): void
    {
        $this->signedInFirm();

        $this->saveTemplates(['subject' => ['en' => '']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('true', $this->client->getCrawler()->filter('#language-tab-en')->attr('aria-selected'));
        self::assertSame('false', $this->client->getCrawler()->filter('#language-tab-cs')->attr('aria-selected'));
        self::assertSelectorExists('#subject-en-error');
    }

    public function testSavingStoresServerAndTemplate(): void
    {
        $this->signedInFirm();

        $this->saveServer(self::SERVER);
        self::assertResponseRedirects($this->url('server'));
        $this->saveTemplates(['subject' => ['cs' => 'Faktura {number} od nás'], 'body' => ['cs' => "Dobrý den,\n{link}"]]);
        self::assertResponseRedirects($this->url());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The “Invoice” template is saved.');

        $server = $this->connection()->fetchAssociative('SELECT host, port, encryption, username, from_address, from_name, verified_at FROM firm_mail_server');
        self::assertSame(['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'invoices@example.com', 'from_address' => 'invoices@example.com', 'from_name' => 'Example Firm', 'verified_at' => null], $server);
        self::assertSame(self::PASSWORD, $this->storedPassword());
        // The English text was left as built in, so only the Czech one is stored.
        self::assertSame([['language' => 'cs', 'subject' => 'Faktura {number} od nás', 'body' => "Dobrý den,\n{link}"]], $this->connection()->fetchAllAssociative('SELECT language, subject, body FROM mail_template'));
    }

    public function testLabelsAreStoredAsStableNamesAndShownAgain(): void
    {
        $this->signedInFirm();

        $this->saveTemplates([
            'subject' => ['cs' => 'Faktura {číslo  dokladu} pro {Název odběratele}', 'en' => 'Invoice {Document number}'],
            'body' => ['cs' => "Splatnost {Datum splatnosti}.\n{Odkaz na doklad}", 'en' => "Due {due_date}.\n{Link to the document}"],
        ]);

        self::assertResponseRedirects($this->url());
        self::assertSame(['cs' => 'Faktura {number} pro {customer_name}', 'en' => 'Invoice {number}'], $this->connection()->fetchAllKeyValue('SELECT language, subject FROM mail_template ORDER BY language'));
        self::assertSame(['cs' => "Splatnost {due_date}.\n{link}", 'en' => "Due {due_date}.\n{link}"], $this->connection()->fetchAllKeyValue('SELECT language, body FROM mail_template ORDER BY language'));
        $crawler = $this->client->request('GET', $this->templateUrl('invoice'));
        self::assertSame('Faktura {Číslo dokladu} pro {Název odběratele}', $crawler->filter('#subject-cs')->attr('value'));
        self::assertSame("Due {Due date}.\n{Link to the document}", $crawler->filter('#body-en')->text(null, false));
    }

    public function testAVeryLongTokenIsRefusedWithAMessage(): void
    {
        $this->signedInFirm();

        $this->saveTemplates(['subject' => ['cs' => 'Faktura {'.str_repeat('a', 120).'}'], 'body' => ['cs' => '{'.str_repeat('b', 300).'} {Odkaz na doklad}']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#subject-cs-error', '{aaaa');
        self::assertSelectorTextContains('#body-cs-error', '…');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testAnUnknownStableNameIsRefusedNotA500(): void
    {
        $this->signedInFirm();

        $this->saveTemplates(['subject' => ['en' => 'Invoice {number_'.str_repeat('x', 90).'}']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#subject-en-error');
    }

    public function testALabelOfTheOtherLanguageIsUnknown(): void
    {
        $this->signedInFirm();

        $this->saveTemplates(['subject' => ['en' => 'Invoice {Číslo dokladu}']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#subject-en-error', 'Unknown variable: {Číslo dokladu}.');
        self::assertSame('Invoice {Číslo dokladu}', $this->client->getCrawler()->filter('#subject-en')->attr('value'));
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testResetGivesTheBuiltInTextBack(): void
    {
        $this->signedInFirm();
        $this->saveTemplates(['subject' => ['en' => 'Own {number}']]);

        $this->saveTemplates(['reset' => 'en']);

        self::assertResponseRedirects($this->url());
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testAFirmInACountryWithoutInvoicingRulesHasNoRemindersCard(): void
    {
        $this->signedInFirm('DE');

        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#reminders-card'));
        self::assertCount(1, $crawler->filter('[data-mail-template="invoice"]'));

        $this->saveTemplates(['subject' => ['cs' => 'Faktura {number} od nás']]);

        self::assertResponseRedirects($this->url());
        self::assertSame(['Faktura {number} od nás'], $this->connection()->fetchFirstColumn('SELECT subject FROM mail_template'));
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT enabled FROM firm_reminders'));
    }

    public function testAReminderTemplateIsStoredPerLanguage(): void
    {
        $this->signedInFirm();
        $crawler = $this->client->request('GET', $this->templateUrl('reminder'));
        self::assertSame('Připomínka platby faktury {Číslo dokladu}', $crawler->filter('#reminder-subject-cs')->attr('value'));
        self::assertStringContainsString('{Zbývá uhradit}', $crawler->filter('#reminder-body-cs')->text(null, false));
        self::assertStringContainsString('{Amount due}', $crawler->filter('#reminder-body-en')->text(null, false));
        $tokens = $crawler->filter('[role="toolbar"][aria-labelledby~="variables-cs"] button[data-token]')->each(static fn ($chip): string => (string) $chip->attr('data-token'));
        self::assertSame(['{Dní po splatnosti}', '{Pořadí upomínky}', '{Zbývá uhradit}', '{Odkaz na doklad}'], \array_slice($tokens, -4), 'the reminder chips come before the link');

        $this->saveTemplates(['reminder_subject' => ['cs' => 'Upomínka {Číslo dokladu}'], 'reminder_body' => ['cs' => "Faktura je {Dní po splatnosti} dní po splatnosti, zbývá {Zbývá uhradit}.\n{Odkaz na doklad}"]]);

        self::assertResponseRedirects($this->url());
        self::assertSame(
            [['kind' => 'reminder', 'language' => 'cs', 'subject' => 'Upomínka {number}', 'body' => "Faktura je {days_overdue} dní po splatnosti, zbývá {amount_due}.\n{link}"]],
            $this->connection()->fetchAllAssociative('SELECT kind, language, subject, body FROM mail_template'),
            'only the changed reminder text is stored; the invoice texts stay built in',
        );

        // A reminder variable in the invoice template is unknown there.
        $this->saveTemplates(['subject' => ['cs' => 'Faktura {Dní po splatnosti}']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#subject-cs-error', '{Dní po splatnosti}');

        // Each kind has its own reset.
        $this->client->request('GET', $this->templateUrl('invoice'));
        self::assertSelectorNotExists('form#mail-reset-cs');
        $reset = $this->client->request('GET', $this->templateUrl('reminder'))->filter('form#mail-reset-cs');
        self::assertCount(1, $reset);
        $this->client->request('POST', $this->templateUrl('reminder'), $reset->form()->getPhpValues());
        self::assertResponseRedirects($this->url());
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testBlankPasswordKeepsTheStoredOne(): void
    {
        $this->signedInFirm();
        $this->saveServer(self::SERVER);

        $this->saveServer(['fromName' => 'Example Billing', 'password' => ''] + self::SERVER);

        self::assertResponseRedirects($this->url('server'));
        self::assertSame('Example Billing', $this->connection()->fetchOne('SELECT from_name FROM firm_mail_server'));
        self::assertSame(self::PASSWORD, $this->storedPassword());
    }

    public function testABlankPasswordIsRefusedWhenTheConnectionChanges(): void
    {
        $this->signedInFirm();
        $this->saveServer(self::SERVER);
        $row = $this->connection()->fetchAssociative('SELECT * FROM firm_mail_server');

        foreach ([['host' => 'mail.example.com'], ['port' => '465'], ['encryption' => 'tls'], ['username' => 'other@example.com']] as $change) {
            $this->saveServer($change + ['password' => ''] + self::SERVER);

            self::assertResponseStatusCodeSame(422, array_key_first($change));
            self::assertSelectorTextContains('#password-error', 'Enter the password again', array_key_first($change));
            self::assertSame($row, $this->connection()->fetchAssociative('SELECT * FROM firm_mail_server'));
            self::assertSame(self::PASSWORD, $this->storedPassword());
        }
    }

    public function testAFirstServerNeedsAPassword(): void
    {
        $this->signedInFirm();

        $this->saveServer(['password' => ''] + self::SERVER);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#password-error');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_mail_server'));
    }

    public function testAPrivateHostIsRefusedAtSave(): void
    {
        $this->signedInFirm();

        foreach (['internal.example.com', '127.0.0.1', 'smtp.example.com:587'] as $host) {
            $this->saveServer(['host' => $host] + self::SERVER);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorExists('#host-error');
        }
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_mail_server'));
    }

    public function testThePasswordIsNeverInTheResponse(): void
    {
        $this->signedInFirm();
        $this->saveServer(['port' => '25'] + self::SERVER);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#port-error');
        $refused = (string) $this->client->getResponse()->getContent();
        $this->saveServer(self::SERVER);

        $this->client->request('GET', $this->url('server'));

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
        $this->signedInFirm();

        $this->saveTemplates(['subject' => ['cs' => 'Faktura {numbr}'], 'body' => ['en' => 'Pay {amount} now {link}']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#subject-cs-error', '{numbr}');
        self::assertSelectorTextContains('#body-en-error', '{amount}');
        self::assertSame('Faktura {numbr}', $this->client->getCrawler()->filter('#subject-cs')->attr('value'), 'the typed text stays');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testTheLinkInTheSubjectIsRefused(): void
    {
        $this->signedInFirm();

        $this->saveTemplates(['subject' => ['cs' => 'Faktura {link}']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#subject-cs-error', '{Odkaz na doklad}');
    }

    public function testAMissingKeyExplainsItself(): void
    {
        $this->keys = \is_string($_SERVER['MAIL_SECRET_KEYS'] ?? null) ? $_SERVER['MAIL_SECRET_KEYS'] : '';
        $_SERVER['MAIL_SECRET_KEYS'] = $_ENV['MAIL_SECRET_KEYS'] = '';
        self::ensureKernelShutdown();
        $this->boot();
        $this->signedInFirm();

        $this->client->request('GET', $this->url('server'));
        self::assertSelectorTextContains('main [role="alert"], main .alert, main', 'MAIL_SECRET_KEYS');
        $this->saveServer(self::SERVER);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'MAIL_SECRET_KEYS');
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_mail_server'));
    }

    public function testAClientRoleGets403(): void
    {
        $this->browser->seed(role: Role::Guest);
        $this->firm();
        $this->browser->signIn();

        $this->client->request('GET', $this->url());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url().'/test');
        self::assertResponseStatusCodeSame(403);
    }

    public function testACsrfFailureChangesNothing(): void
    {
        $this->signedInFirm();

        $this->client->request('POST', $this->url(), ['_token' => 'wrong', 'section' => 'server'] + self::SERVER);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM firm_mail_server'));
    }

    public function testTheTestMailGoesToTheUserAndSetsVerifiedAt(): void
    {
        $this->signedInFirm();
        $this->saveServer(self::SERVER);

        $this->sendTest();

        self::assertResponseRedirects($this->url('server'));
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
        $this->signedInFirm();
        $this->saveServer(self::SERVER);
        $this->gateway()->failNext(1, true);

        $this->sendTest();
        $this->client->followRedirect();

        self::assertSelectorTextContains('main', 'SMTP 550: the mail server refused the message.');
        self::assertNull($this->connection()->fetchOne('SELECT verified_at FROM firm_mail_server'));
    }

    public function testASettingsProblemClearsTheVerificationAndShowsTheLastResult(): void
    {
        $this->signedInFirm();
        $this->saveServer(self::SERVER);
        $this->sendTest();
        self::assertNotNull($this->connection()->fetchOne('SELECT verified_at FROM firm_mail_server'));
        Clock::set(new MockClock('2026-11-02 12:30:00'));
        $this->gateway()->failNextAsSettingsProblem();

        $this->sendTest();
        $this->client->followRedirect();

        self::assertNull($this->connection()->fetchOne('SELECT verified_at FROM firm_mail_server'));
        self::assertSame(['last_test_at' => '2026-11-02 12:30:00', 'last_test_error' => 'SMTP 535: the mail server refused the login.'], $this->connection()->fetchAssociative('SELECT last_test_at, last_test_error FROM firm_mail_server'));
        self::assertSelectorTextNotContains('main', 'Verified on');
        self::assertSelectorTextContains('#mail-test-result', 'Last test on 2 Nov 2026 at 12:30 failed: SMTP 535: the mail server refused the login.');
    }

    public function testAPassingTestShowsItsTime(): void
    {
        $this->signedInFirm();
        $this->saveServer(self::SERVER);

        $this->sendTest();
        $this->client->followRedirect();

        self::assertSelectorTextContains('#mail-test-result', 'Verified on 2 Nov 2026 at 12:00.');
    }

    public function testAHostNotFoundAndAMalformedHostHaveTheirOwnMessages(): void
    {
        $this->signedInFirm();

        $this->saveServer(['host' => 'nowhere.invalid'] + self::SERVER);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#host-error', 'No server of this name was found');

        $this->saveServer(['host' => 'smtp_example.com'] + self::SERVER);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#host-error', 'Enter a host name or an IP address');

        $this->saveServer(['host' => 'internal.example.com'] + self::SERVER);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#host-error', 'cannot be reached from here');
    }

    public function testResetIsAFormOfItsOwnAndAsksFirst(): void
    {
        $this->signedInFirm();
        $this->saveTemplates(['subject' => ['cs' => 'Vlastní {Číslo dokladu}']]);

        $crawler = $this->client->request('GET', $this->templateUrl('invoice'));

        self::assertCount(0, $crawler->filter('#mail-template button[name="reset"]'), 'no reset button inside the save form');
        $reset = $crawler->filter('form#mail-reset-cs');
        self::assertCount(1, $reset);
        // In the sheet its host posts the form itself, so it asks with confirm-submit.
        self::assertSame('confirm-submit', $reset->attr('data-controller'));
        self::assertNotSame('', (string) $reset->attr('data-confirm-title'));
        self::assertCount(1, $crawler->filter('button[type="submit"][form="mail-reset-cs"]'));
        self::assertCount(0, $crawler->filter('form#mail-reset-en'));
        $this->client->request('POST', $this->templateUrl('invoice'), $reset->form()->getPhpValues());
        self::assertResponseRedirects($this->url());
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM mail_template'));
    }

    public function testATestWithoutAServerSendsNothing(): void
    {
        $this->signedInFirm();

        $this->client->request('GET', $this->url('server'));
        self::assertSelectorNotExists('button[form="mail-test"]');
    }

    public function testTheTestIsRateLimited(): void
    {
        $this->signedInFirm();
        $this->saveServer(self::SERVER);

        for ($i = 0; $i < 6; ++$i) {
            $this->sendTest();
        }
        $this->client->followRedirect();

        self::assertCount(5, $this->gateway()->messages());
        self::assertSelectorTextContains('main', 'Too many test e-mails');
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

    private function url(?string $tab = null): string
    {
        return '/settings/firm/mail'.(null === $tab ? '' : '?tab='.$tab);
    }

    private function templateUrl(string $kind): string
    {
        return '/settings/firm/mail/templates/'.$kind;
    }

    /**
     * @param array<string, string> $values
     */
    private function saveServer(array $values): void
    {
        $crawler = $this->client->request('GET', $this->url('server'));
        $form = $crawler->filter('#mail-server')->form();
        $this->client->request('POST', $this->url(), $values + $form->getPhpValues());
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

    private function sendTest(): void
    {
        $crawler = $this->client->request('GET', $this->url('server'));
        $this->client->request('POST', $this->url().'/test', $crawler->filter('#mail-test')->form()->getPhpValues());
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
