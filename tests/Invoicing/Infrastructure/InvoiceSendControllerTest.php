<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Identity\Domain\Role;
use App\Invoicing\Application\FirmInvoicingSettings;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\MailTemplates;
use App\Invoicing\Application\SendRefused;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\MailEncryption;
use App\Invoicing\Domain\MailTemplateKind;
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
use App\Tests\Identity\Support\SignedInBrowser;
use App\Tests\Import\Support\RunMessages;
use App\Tests\Support\Mail\RecordingMailGateway;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @phpstan-type Dialog array{_token: string, _submission: string, return: string, to?: list<string>, cc?: list<string>, others: string, copyToMe?: string, subject: string, body: string}
 */
final class InvoiceSendControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private TenantContext $context;
    private TenantId $tenant;
    private LegalEntity $firm;
    private Series $series;
    private Party $customer;

    protected function setUp(): void
    {
        self::mockTime('2026-11-03 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
        RecordingMailGateway::reset();
    }

    protected function tearDown(): void
    {
        RecordingMailGateway::reset();
        parent::tearDown();
    }

    public function testTheDialogOpensFilledInFromTheContactForThisFirm(): void
    {
        $this->signedIn();
        $this->scoped(function (): void {
            $this->em()->persist(new PartyEmail($this->em()->find(Party::class, $this->customer->id()) ?? throw new \LogicException('Customer.'), 'accounts@example.com', EmailPurpose::Copy));
            $this->em()->flush();
        });
        $invoice = $this->issued();

        $crawler = $this->open($invoice);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Send invoice 20260001');
        self::assertSelectorTextContains('dl', 'Example Firm <invoices@example.com>');
        self::assertSame(['billing@example.com'], $this->checked($crawler, 'to[]'));
        self::assertSame(['accounts@example.com'], $this->checked($crawler, 'cc[]'));
        self::assertSelectorTextContains('label[for="recipient-2"]', 'accounts@example.com (copy)');
        self::assertSame('Faktura 20260001', $crawler->filter('#subject')->attr('value'));
        // Filled in, with the link as a readable token in the document's language.
        self::assertStringStartsWith('Na základě Vaší objednávky Vám zasílám fakturu č. 20260001 na částku', $crawler->filter('#body')->text());
        self::assertStringEndsWith('{Odkaz na doklad}', $crawler->filter('#body')->text());
        self::assertSelectorTextContains('#body-help', '{Odkaz na doklad}');
        self::assertNotNull($crawler->filter('#copyToMe')->attr('checked'));
        self::assertSelectorTextContains('label[for="copyToMe"]', 'office@example.com');
        // The method is left out while a link is the only one.
        self::assertSelectorNotExists('[name="method"]');
        self::assertSelectorNotExists('[data-sent-before]');
    }

    public function testInADialogTheMessageShowsAtAGlanceWithSendReadyForOneClick(): void
    {
        $this->signedIn();
        $invoice = $this->issued();

        $crawler = $this->openDialog($invoice, 'tab=unpaid');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('<dialog', ltrim((string) $this->client->getResponse()->getContent()));
        self::assertSame('Send invoice 20260001', $crawler->filter('dialog h2')->text());
        self::assertStringContainsString('max-w-[560px]', (string) $crawler->filter('dialog')->attr('class'));
        // Send has the focus: one click or Enter sends what the dialog shows.
        $send = $crawler->filter('footer button#send-submit[type="submit"][form="send-form"]');
        self::assertNotNull($send->attr('autofocus'));
        self::assertSame('dialog#dismiss', $crawler->filter('footer button')->first()->attr('data-action'));
        self::assertSame(['billing@example.com'], $this->checked($crawler, 'to[]'));
        // Subject, text and other recipients wait behind "Edit text", in the same dialog.
        $edit = $crawler->filter('details[data-send-edit]');
        self::assertNull($edit->attr('open'));
        self::assertSame('Edit text', $edit->filter('summary')->text());
        self::assertCount(1, $edit->filter('#subject'));
        self::assertCount(1, $edit->filter('#body'));
        self::assertCount(1, $edit->filter('#others'));
        $preview = $crawler->filter('[data-send-preview]');
        self::assertStringContainsString('Faktura 20260001', $preview->filter('[data-send-preview-subject]')->text());
        self::assertStringStartsWith('Na základě Vaší objednávky', $preview->filter('[data-send-preview-body]')->text());
        self::assertSame('Odkaz na doklad', trim($preview->filter('[data-link-token]')->text()));
        self::assertSame('tab=unpaid', $crawler->filter('input[name="return"]')->attr('value'));
    }

    public function testOneClickInTheDialogStoresOneSendAndLeadsBackToTheList(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialogValues($invoice, 'tab=unpaid');

        $this->client->request('POST', '/invoices/'.$invoice->id().'/send', $values, server: ['HTTP_X_FORESI_DIALOG' => '1']);
        $this->client->request('POST', '/invoices/'.$invoice->id().'/send', $values, server: ['HTTP_X_FORESI_DIALOG' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame(['location' => '/invoices?tab=unpaid'], json_decode((string) $this->client->getResponse()->getContent(), true));
        $row = $this->onlySend();
        self::assertSame('queued', $row['status']);
        self::assertSame(['billing@example.com'], json_decode($this->string($row['recipients_to']), true));
        self::assertSame('Faktura 20260001', $row['subject']);
        $this->client->request('GET', '/invoices?tab=unpaid');
        self::assertAnySelectorTextContains('[role="status"]', 'Invoice 20260001 is queued for sending to billing@example.com.');
    }

    public function testAfterADialogOverTheHistoryTheHistoryShowsTheMessage(): void
    {
        $this->signedIn();
        $invoice = $this->issued();

        $this->client->request('POST', '/invoices/'.$invoice->id().'/send', $this->dialogValues($invoice), server: ['HTTP_X_FORESI_DIALOG' => '1']);
        $this->client->request('GET', '/invoices/'.$invoice->id().'/history');

        self::assertAnySelectorTextContains('[role="status"]', 'Invoice 20260001 is queued for sending to billing@example.com.');
        self::assertSame(1, $this->sendCount());
    }

    public function testARefusalInTheDialogOpensTheTextAndKeepsWhatWasTyped(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialogValues($invoice);
        $values['others'] = 'boss@example.com, not-an-address';

        $this->client->request('POST', '/invoices/'.$invoice->id().'/send', $values, server: ['HTTP_X_FORESI_DIALOG' => '1']);

        self::assertResponseStatusCodeSame(422);
        $crawler = new Crawler((string) $this->client->getResponse()->getContent());
        self::assertNotNull($crawler->filter('details[data-send-edit]')->attr('open'));
        self::assertStringContainsString('not-an-address', $crawler->filter('[data-error-summary]')->text());
        self::assertSame('boss@example.com, not-an-address', $crawler->filter('#others')->attr('value'));
        self::assertNull($crawler->filter('#send-submit')->attr('autofocus'));
        self::assertSame(0, $this->sendCount());
    }

    public function testInADialogAContactWithoutAddressOpensTheTextToTypeOne(): void
    {
        $this->signedIn();
        $this->scoped(function (): void {
            $this->connection()->executeStatement('DELETE FROM party_email');
        });
        $invoice = $this->issued();

        $crawler = $this->openDialog($invoice);

        self::assertNotNull($crawler->filter('details[data-send-edit]')->attr('open'));
        self::assertNull($crawler->filter('#send-submit')->attr('autofocus'));
    }

    public function testInADialogWithoutAServerItExplainsAndClosesOrLeadsToTheSettings(): void
    {
        $this->signedIn(server: false);
        $invoice = $this->issued();

        $crawler = $this->openDialog($invoice);

        self::assertCount(0, $crawler->filter('form'));
        self::assertSame('false', $crawler->filter('dialog')->attr('data-dialog-fields-value'));
        self::assertStringContainsString('The firm has no mail server', $crawler->filter('dialog')->text());
        self::assertSame('dialog#dismiss', $crawler->filter('footer button')->attr('data-action'));
        self::assertSame('/settings/firms/'.$this->firm->id().'/mail?tab=server', $crawler->filter('footer a')->attr('href'));
    }

    public function testADraftIsInTheDocumentLanguage(): void
    {
        $this->signedIn(locale: 'cs');
        $invoice = $this->issued(DocumentLanguage::English);

        $crawler = $this->open($invoice);

        self::assertSelectorTextContains('h1', 'Odeslat fakturu 20260001');
        self::assertSame('Invoice 20260001', $crawler->filter('#subject')->attr('value'));
        self::assertSame('en', $crawler->filter('#body')->attr('lang'));
        self::assertStringContainsString('due on 15 Nov 2026', $crawler->filter('#body')->text());
        self::assertStringEndsWith('{Link to the document}', $crawler->filter('#body')->text());
    }

    public function testAContactWithoutAnInvoicingAddressOffersTheDocumentsOne(): void
    {
        $this->signedIn(email: EmailPurpose::General);
        $invoice = $this->issued();

        $crawler = $this->open($invoice);

        self::assertSame(['billing@example.com'], $this->checked($crawler, 'to[]'));
        self::assertSelectorExists('[data-recipient-note]');
    }

    public function testPostingStoresEditedSubjectAndBody(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialog($invoice);
        $values['others'] = 'boss@example.com; second@example.com';
        $values['subject'] = 'Faktura 20260001 k úhradě';
        $values['body'] = "Dobrý den,\nposílám fakturu.\n\n{Odkaz na doklad}";

        $this->post($invoice, $values);

        self::assertResponseRedirects('/invoices');
        $row = $this->onlySend();
        self::assertSame('queued', $row['status']);
        self::assertSame(['billing@example.com', 'boss@example.com', 'second@example.com'], json_decode($this->string($row['recipients_to']), true));
        self::assertSame([], json_decode($this->string($row['recipients_cc']), true));
        self::assertSame('office@example.com', $row['copy_to']);
        self::assertSame('Faktura 20260001 k úhradě', $row['subject']);
        self::assertSame("Dobrý den,\nposílám fakturu.\n\n{link}", $row['body']);
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role="status"]', 'Invoice 20260001 is queued for sending to billing@example.com, boss@example.com, second@example.com.');
    }

    public function testAnUntickedCopyGoesNowhere(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialog($invoice);
        unset($values['copyToMe']);

        $this->post($invoice, $values);

        self::assertResponseRedirects('/invoices');
        self::assertNull($this->onlySend()['copy_to']);
    }

    public function testDoubleSubmitSendsOnce(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialog($invoice);

        $this->post($invoice, $values);
        $this->post($invoice, $values);

        self::assertResponseRedirects('/invoices');
        self::assertSame(1, $this->sendCount());
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role="status"]', 'Invoice 20260001 is already queued for sending.');
    }

    public function testErrorsKeepWhatWasTyped(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialog($invoice);
        $values['others'] = 'boss@example.com, not-an-address';
        $values['body'] = 'Edited text {Odkaz na doklad}';
        unset($values['copyToMe']);

        $crawler = $this->post($invoice, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->sendCount());
        self::assertStringStartsWith('Error:', $crawler->filter('title')->text());
        self::assertSelectorTextContains('[data-error-summary]', 'Other recipients: not-an-address is not a valid e-mail address.');
        self::assertSame('#others', $crawler->filter('[data-error-summary] a')->attr('href'));
        self::assertSame('true', $crawler->filter('#others')->attr('aria-invalid'));
        self::assertNotNull($crawler->filter('#others')->attr('autofocus'));
        self::assertSame('boss@example.com, not-an-address', $crawler->filter('#others')->attr('value'));
        self::assertSame('Edited text {Odkaz na doklad}', $crawler->filter('#body')->text());
        self::assertNull($crawler->filter('#copyToMe')->attr('checked'));
        self::assertSame(['billing@example.com'], $this->checked($crawler, 'to[]'));
        // The token was not used, so the corrected form still goes through.
        $values['others'] = 'boss@example.com';
        $this->post($invoice, $values);
        self::assertResponseRedirects('/invoices');
        self::assertSame(1, $this->sendCount());
    }

    public function testMoreThanFiveOtherRecipientsAreRefused(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialog($invoice);
        $values['others'] = implode(' ', array_map(static fn (int $n): string => 'other'.$n.'@example.com', range(1, 6)));

        $this->post($invoice, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#others-error', 'There can be at most 5 other recipients.');
        self::assertSame(0, $this->sendCount());
    }

    /**
     * The dialog's text is filled in, so braces in a customer's name or a
     * description are text; only the link's label is a token.
     */
    public function testBracesInCustomerDataAreSentAsTyped(): void
    {
        $this->signedIn();
        $this->scoped(function (): void {
            $customer = $this->em()->find(Party::class, $this->customer->id()) ?? throw new \LogicException('Customer.');
            $customer->rename('Projekt {Alfa} a.s.');
            $firm = $this->em()->find(LegalEntity::class, $this->firm->id()) ?? throw new \LogicException('Firm.');
            self::getContainer()->get(MailTemplates::class)->save($firm, MailTemplateKind::Invoice, DocumentLanguage::Czech, 'Faktura {number} pro {customer_name}', "{description}\n{link}");
            $this->em()->flush();
        });
        $invoice = $this->issued(description: 'Ref {number}');
        $values = $this->dialog($invoice);
        self::assertSame('Faktura 20260001 pro Projekt {Alfa} a.s.', $values['subject']);
        self::assertSame("Ref {number}\n{Odkaz na doklad}", $values['body']);

        $this->post($invoice, $values);

        self::assertResponseRedirects('/invoices');
        $row = $this->onlySend();
        self::assertSame('Faktura 20260001 pro Projekt {Alfa} a.s.', $row['subject']);
        self::assertSame("Ref {number}\n{link}", $row['body']);
    }

    public function testTheLinkLabelIsReadInAnySpelling(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialog($invoice);
        $values['body'] = 'Odkaz: {odkaz na DOKLAD} a {link}, ne {Číslo dokladu}';

        $this->post($invoice, $values);

        self::assertSame('Odkaz: {link} a {link}, ne {Číslo dokladu}', $this->onlySend()['body']);
    }

    public function testTheLinkCannotGoInTheSubject(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialog($invoice);
        $values['subject'] = 'Invoice {Odkaz na doklad}';

        $this->post($invoice, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#subject-error');
        self::assertSame(0, $this->sendCount());
    }

    public function testNoRecipientIsRefusedOnTheGroup(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialog($invoice);
        unset($values['to']);

        $crawler = $this->post($invoice, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#recipients-error', 'Tick or type at least one recipient.');
        self::assertSame('recipients-error', $crawler->filter('#recipient-1')->attr('aria-describedby'));
        self::assertNotNull($crawler->filter('#recipient-1')->attr('autofocus'));
        self::assertSame(0, $this->sendCount());
    }

    public function testARefusalOfNoFieldTakesTheFocusItself(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialog($invoice);
        $this->exhaustFirmLimit();

        $crawler = $this->post($invoice, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-error-summary]', 'Too many sends in a short time.');
        self::assertNotNull($crawler->filter('[data-error-summary]')->attr('autofocus'));
        self::assertSame(0, $this->sendCount());
    }

    public function testAnAlreadySentInvoiceSaysWhenAndCanBeSentAgain(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $this->post($invoice, $this->dialog($invoice));
        $this->scoped(static function (): void {
            $mail = self::getContainer()->get('messenger.transport.mail');
            self::assertInstanceOf(InMemoryTransport::class, $mail);
            new RunMessages($mail, self::getContainer()->get(MessageBusInterface::class))->run();
        });

        $this->open($invoice);

        self::assertSelectorTextContains('[data-sent-before]', '2026-11-03 11:00');
        $this->post($invoice, $this->dialog($invoice));
        self::assertResponseRedirects('/invoices');
        self::assertSame(2, $this->sendCount());
    }

    public function testWithoutAServerTheDialogExplainsAndLinksToTheSettings(): void
    {
        $this->signedIn(server: false);
        $invoice = $this->issued();

        $crawler = $this->open($invoice);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form #subject');
        self::assertSelectorTextContains('main', 'The firm has no mail server');
        self::assertSame('/settings/firms/'.$this->firm->id().'/mail?tab=server', $crawler->selectLink('Set up the mail server')->attr('href'));
    }

    public function testAMemberSeesNoLinkToTheOwnersSettings(): void
    {
        $this->signedIn(Role::Member, server: false);
        $invoice = $this->issued();

        $this->open($invoice);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href*="/mail"]');
        self::assertSelectorTextContains('main', 'The owner of the organisation sets up the server');
    }

    /**
     * A refusal that names no field shows its reason as "invoice.send.error.{reason}".
     */
    public function testEveryRefusalHasATranslationInBothLanguages(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $reasons = array_values(array_filter(new \ReflectionClass(SendRefused::class)->getConstants(), \is_string(...)));
        self::assertNotEmpty($reasons);
        foreach (['cs', 'en'] as $locale) {
            $catalogue = $translator->getCatalogue($locale);
            foreach ($reasons as $reason) {
                self::assertTrue($catalogue->defines('invoice.send.error.'.$reason, 'messages+intl-icu'), $locale.': '.$reason);
            }
        }
    }

    public function testStaffOnly(): void
    {
        $this->signedIn(Role::Subcontractor);
        $id = '019a0000-0000-7000-8000-000000000001';

        foreach (['GET', 'POST'] as $method) {
            $this->client->request($method, '/invoices/'.$id.'/send');
            self::assertResponseStatusCodeSame(403, $method);
        }
    }

    public function testAnotherTenantsInvoiceIs404(): void
    {
        $this->signedIn();
        $foreign = $this->foreignInvoice();

        $this->client->request('GET', '/invoices/'.$foreign.'/send');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/invoices/'.$foreign.'/send', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->sendCount());
    }

    public function testAWrongCsrfTokenSendsNothing(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialog($invoice);
        $values['_token'] = 'wrong';

        $this->post($invoice, $values);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->sendCount());
    }

    public function testATrashedInvoiceOffersNoSend(): void
    {
        $this->signedIn();
        $invoice = $this->issued();
        $values = $this->dialog($invoice);
        $this->scoped(static fn () => self::getContainer()->get(TrashInvoice::class)->trash($invoice->id(), 'Wrong customer'));

        $this->client->request('GET', '/invoices?tab=trash');
        self::assertSelectorNotExists('a[href="/invoices/'.$invoice->id().'/send"]');
        $this->open($invoice);
        self::assertResponseRedirects('/invoices');
        $this->post($invoice, $values);
        self::assertResponseRedirects('/invoices');
        self::assertSame(0, $this->sendCount());
        $this->client->followRedirect();
        self::assertAnySelectorTextContains('[role="alert"]', 'Invoice 20260001 is in the trash, so it cannot be sent.');
    }

    public function testTheListOffersSendWithTheWayBack(): void
    {
        $this->signedIn();
        $invoice = $this->issued();

        $crawler = $this->client->request('GET', '/invoices?q=2026');

        self::assertSame('/invoices/'.$invoice->id().'/send?return=q%3D2026', $crawler->selectLink('Send')->attr('href'));
        $values = $this->dialog($invoice, 'q=2026');
        $this->post($invoice, $values);
        self::assertResponseRedirects('/invoices?q=2026');
    }

    private function exhaustFirmLimit(): void
    {
        self::getContainer()->get('limiter.invoice_send_firm')->create($this->firm->id())->consume(500);
    }

    private function open(Invoice $invoice, string $return = ''): Crawler
    {
        return $this->client->request('GET', '/invoices/'.$invoice->id().'/send'.('' === $return ? '' : '?return='.rawurlencode($return)));
    }

    private function openDialog(Invoice $invoice, string $return = ''): Crawler
    {
        $this->client->request('GET', '/invoices/'.$invoice->id().'/send'.('' === $return ? '' : '?return='.rawurlencode($return)), server: ['HTTP_X_FORESI_DIALOG' => '1']);

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost/');
    }

    /**
     * What the dialog would post as it opens.
     *
     * @return Dialog
     */
    private function dialogValues(Invoice $invoice, string $return = ''): array
    {
        /** @var Dialog $values */
        $values = $this->openDialog($invoice, $return)->filter('form#send-form')->form()->getPhpValues();

        return $values;
    }

    /**
     * What the browser would send from the dialog as it opens.
     *
     * @return Dialog
     */
    private function dialog(Invoice $invoice, string $return = ''): array
    {
        $crawler = $this->open($invoice, $return);
        /** @var Dialog $values */
        $values = $crawler->filter('form[action$="/send"]')->form()->getPhpValues();

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function post(Invoice $invoice, array $values): Crawler
    {
        return $this->client->request('POST', '/invoices/'.$invoice->id().'/send', $values);
    }

    /**
     * @return list<string>
     */
    private function checked(Crawler $crawler, string $name): array
    {
        return array_values(array_map(static fn (\DOMNode $node): string => $node instanceof \DOMElement ? $node->getAttribute('value') : '', iterator_to_array($crawler->filter('input[name="'.$name.'"][checked]'))));
    }

    /**
     * @return array<string, mixed>
     */
    private function onlySend(): array
    {
        $rows = $this->connection()->fetchAllAssociative('SELECT * FROM invoice_send');
        self::assertCount(1, $rows);

        return $rows[0];
    }

    private function sendCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM invoice_send');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private function issued(DocumentLanguage $language = DocumentLanguage::Czech, string $description = 'Consulting'): Invoice
    {
        return $this->scoped(function () use ($language, $description): Invoice {
            $day = new \DateTimeImmutable('2026-11-01');
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

            return self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput($this->firm->id(), $this->series->id(), null, $this->customer->id(), null, $day, $day, new \DateTimeImmutable('2026-11-15'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', $description, '', '', $language, [$line]));
        });
    }

    private function foreignInvoice(): string
    {
        $tenant = $this->browser->fixtures->tenant('Foreign Tenant');
        $this->context->switchTo($tenant);
        try {
            [$firm, $series, $customer] = $this->firmWithCustomer(true, EmailPurpose::Invoices);
            $day = new \DateTimeImmutable('2026-11-01');
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('100.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

            return self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput($firm->id(), $series->id(), null, $customer->id(), null, $day, $day, new \DateTimeImmutable('2026-12-01'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Foreign', '', '', DocumentLanguage::Czech, [$line]))->id();
        } finally {
            $this->context->reset();
        }
    }

    private function signedIn(Role $role = Role::Owner, string $locale = 'en', bool $server = true, EmailPurpose $email = EmailPurpose::Invoices): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], $locale, $role);
        $this->scoped(function () use ($server, $email): void {
            [$this->firm, $this->series, $this->customer] = $this->firmWithCustomer($server, $email);
        });
        $this->browser->signIn();
    }

    /**
     * A payer firm with a contact address and, if asked, a mail server, and a customer
     * with one address, in the active tenant.
     *
     * @return array{LegalEntity, Series, Party}
     */
    private function firmWithCustomer(bool $server, EmailPurpose $purpose): array
    {
        $em = $this->em();
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1', [self::IBAN]);
        $em->persist($firm);
        $series = new Series($firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $series->markDefault();
        $em->persist($series);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $em->persist($customer);
        $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $em->persist(new PartyEmail($customer, 'billing@example.com', $purpose));
        if ($server) {
            $em->persist(FirmMailServer::define($firm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', self::getContainer()->get(MailSecrets::class)->seal('correct-horse-battery'), 'invoices@example.com', 'Example Firm'));
        }
        $em->flush();
        $settings = self::getContainer()->get(FirmInvoicingSettings::class);
        $own = $settings->for($firm);
        $own->change($own->rounding(), '', '', '', 'office@example.com', '', '');
        $settings->save($own);
        $em->flush();

        return [$firm, $series, $customer];
    }

    /**
     * Runs outside a request, which has no tenant of its own.
     *
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
        return $this->browser->em();
    }
}
