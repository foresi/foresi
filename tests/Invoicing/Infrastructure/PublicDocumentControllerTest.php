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
use App\Invoicing\Application\LinkTokens;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\SendInvoice;
use App\Invoicing\Application\SendRequest;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\FirmMailServer;
use App\Invoicing\Domain\LinkAudience;
use App\Invoicing\Domain\MailEncryption;
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
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Tests\Import\Support\RunMessages;
use App\Tests\Support\Mail\RecordingMailGateway;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PublicDocumentControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string IBAN = 'CZ6508000000192000145399';
    private const string CSP = "default-src 'none'; style-src 'self'; font-src 'self'; img-src 'self'; object-src 'self'; frame-src 'self'; frame-ancestors 'self'";
    /** What a browser sends when a person presses a button on the page. */
    private const array CLICK = ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'document', 'HTTP_SEC_FETCH_USER' => '?1'];
    /** What a browser sends when it loads the embedded PDF itself. */
    private const array EMBED = ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_SEC_FETCH_DEST' => 'object'];

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private TenantContext $context;
    private TenantId $tenant;

    /** @var array{string, string, string}|null */
    private ?array $firm = null;

    protected function setUp(): void
    {
        self::mockTime('2026-11-03 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        // A Czech browser, unless a test says otherwise.
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'cs-CZ,cs;q=0.9');
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
        $this->tenant = $this->browser->fixtures->tenant('Tenant A');
        RecordingMailGateway::reset();
    }

    protected function tearDown(): void
    {
        RecordingMailGateway::reset();
        parent::tearDown();
    }

    public function testTheLinkNeedsNoLoginAndCreatesNoSession(): void
    {
        $sent = $this->sentDocument();

        $crawler = $this->client->request('GET', '/d/'.$sent['token']);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertSelectorTextContains('h1', 'Faktura 20260001');
        self::assertSelectorTextContains('main', 'Example Firm s.r.o.');
        self::assertSelectorTextContains('main', 'Example Customer a.s.');
        // Grouped as on the PDF, with no-break spaces, and in crowns as people write them.
        self::assertMatchesRegularExpression('/^K úhradě 1\x{a0}210,00\x{a0}Kč Splatnost 15\. 11\. 2026$/u', trim($crawler->filter('section dl')->first()->text()));
        self::assertSame('/d/'.$sent['token'].'/pdf', $crawler->filter('object')->attr('data'));
        self::assertSame('/d/'.$sent['token'].'/open', $crawler->filter('section a')->first()->attr('href'));
        self::assertSame('Otevřít PDF', trim($crawler->filter('section a')->first()->text()));
        self::assertSame('/d/'.$sent['token'].'/download', $crawler->filter('section a')->eq(1)->attr('href'));
        // The number is in the title only.
        self::assertSame(1, substr_count($crawler->filter('section')->first()->text(), '20260001'));
        // No application shell, no script.
        self::assertSelectorNotExists('script');
        self::assertSelectorNotExists('nav');
        self::assertStringNotContainsString('Foresi', (string) $this->client->getResponse()->getContent());
    }

    public function testThePageIsInTheLanguageOfTheDocument(): void
    {
        $sent = $this->sentDocument(DocumentLanguage::English);

        $this->client->request('GET', '/d/'.$sent['token'], server: ['HTTP_ACCEPT_LANGUAGE' => 'cs-CZ,cs']);

        self::assertSelectorTextContains('h1', 'Invoice 20260001');
        self::assertSelectorTextContains('dl', 'Amount to pay');
        self::assertSelectorTextContains('dl', '15 Nov 2026');
        self::assertSelectorTextContains('#payment-title', 'Pay by bank transfer');
        self::assertSame('en', $this->client->getCrawler()->filter('html')->attr('lang'));
    }

    public function testThePdfBytesAreTheStoredOnes(): void
    {
        $sent = $this->sentDocument();
        $stored = $this->storedPdf($sent['send']);
        $this->inTenant(function () use ($sent): void {
            $connection = self::getContainer()->get(Connection::class);
            $connection->executeStatement("UPDATE invoice SET description = 'Changed after sending', version = version + 1 WHERE id = ?", [$sent['invoice']]);
        });

        foreach (['/pdf' => 'inline', '/open' => 'inline', '/download' => 'attachment'] as $suffix => $disposition) {
            $this->client->request('GET', '/d/'.$sent['token'].$suffix);

            self::assertResponseIsSuccessful();
            $response = $this->client->getResponse();
            self::assertSame('application/pdf', $response->headers->get('Content-Type'));
            self::assertSame($disposition.'; filename=20260001.pdf', $response->headers->get('Content-Disposition'));
            self::assertSame($stored, $this->client->getInternalResponse()->getContent());
        }
    }

    public function testUnknownRevokedAndCancelledAnswerAlike(): void
    {
        $revoked = $this->sentDocument();
        $this->command($revoked['send']);
        $cancelled = $this->queuedDocument();
        $this->inTenant(static function () use ($cancelled): void {
            self::getContainer()->get(TrashInvoice::class)->trash($cancelled['invoice'], 'Wrong customer');
        });

        $bodies = [];
        foreach ([str_repeat('A', 43), $revoked['token'], $cancelled['token']] as $token) {
            foreach (['', '/pdf', '/open', '/download'] as $suffix) {
                $this->client->request('GET', '/d/'.$token.$suffix);
                self::assertResponseStatusCodeSame(404);
                $bodies[] = (string) $this->client->getResponse()->getContent();
            }
        }

        self::assertCount(1, array_unique($bodies));
        self::assertStringContainsString('Odkaz není k dispozici', $bodies[0]);
        self::assertStringNotContainsString('20260001', $bodies[0]);
        self::assertStringNotContainsString('Example', $bodies[0]);
    }

    public function testAMalformedTokenIs404(): void
    {
        $this->client->request('GET', '/d/'.str_repeat('A', 43));
        $unknown = (string) $this->client->getResponse()->getContent();

        foreach (['/d/short', '/d/'.str_repeat('A', 44), '/d/'.str_repeat('A', 42).'%24', '/d/'.str_repeat('A', 42).'.', '/d/short/pdf', '/d/'.str_repeat('A', 43).'/x/y', '/d/'.str_repeat('A', 300), '/d/'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
            self::assertSame($unknown, (string) $this->client->getResponse()->getContent(), $path);
        }
    }

    public function testAChangedStoredFileIsNotServed(): void
    {
        $sent = $this->sentDocument();
        $path = $this->pdfPath($sent['send']);
        file_put_contents($path, '%PDF-1.7 something else');
        $log = self::getContainer()->getParameter('kernel.cache_dir').'/test.log';
        $before = is_file($log) ? (int) filesize($log) : 0;

        $this->client->request('GET', '/d/'.$sent['token'].'/open', server: self::CLICK);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('Odkaz není k dispozici', (string) $this->client->getResponse()->getContent());
        $written = (string) file_get_contents($log, offset: $before);
        self::assertStringContainsString('The kept PDF of the send', $written);
        self::assertStringContainsString('changed after it was sent', $written);
        self::assertSame(0, $this->readState($sent['send'])['read_count']);
    }

    public function testHeadersOnBothRoutes(): void
    {
        $sent = $this->sentDocument();

        foreach (['/d/'.$sent['token'], '/d/'.$sent['token'].'/pdf', '/d/'.$sent['token'].'/open', '/d/'.$sent['token'].'/download', '/d/'.str_repeat('A', 43), '/d/'.str_repeat('A', 43).'/pdf', '/d/'.$sent['token'].'/x/y', '/d/'.str_repeat('A', 300)] as $path) {
            $this->client->request('GET', $path);
            $headers = $this->client->getResponse()->headers;
            self::assertSame('noindex, nofollow', $headers->get('X-Robots-Tag'), $path);
            self::assertSame('no-referrer', $headers->get('Referrer-Policy'), $path);
            self::assertSame('no-store, private', $headers->get('Cache-Control'), $path);
            self::assertSame(self::CSP, $headers->get('Content-Security-Policy'), $path);
            self::assertSame('nosniff', $headers->get('X-Content-Type-Options'), $path);
            self::assertSame('SAMEORIGIN', $headers->get('X-Frame-Options'), $path);
        }
    }

    public function testThePageItselfDoesNotCountAsRead(): void
    {
        $sent = $this->sentDocument();

        $this->client->request('GET', '/d/'.$sent['token'], server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->client->request('GET', '/d/'.$sent['token'], server: ['HTTP_SEC_FETCH_SITE' => 'none']);

        self::assertSame(['first_read_at' => null, 'read_count' => 0], $this->readState($sent['send']));
    }

    public function testAPdfFetchWithoutFetchMetadataDoesNotCount(): void
    {
        $sent = $this->sentDocument();

        $this->client->request('GET', '/d/'.$sent['token'].'/open');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/d/'.$sent['token'].'/open', server: ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_USER' => '?1']);
        $this->client->request('GET', '/d/'.$sent['token'].'/open', server: ['HTTP_SEC_FETCH_SITE' => 'none', 'HTTP_SEC_FETCH_USER' => '?1']);
        $this->client->request('HEAD', '/d/'.$sent['token'].'/open', server: self::CLICK);
        self::assertResponseIsSuccessful();

        self::assertSame(['first_read_at' => null, 'read_count' => 0], $this->readState($sent['send']));
    }

    public function testTheEmbeddedPdfNeverCounts(): void
    {
        $sent = $this->sentDocument();

        $this->client->request('GET', '/d/'.$sent['token'].'/pdf', server: self::EMBED);
        self::assertResponseIsSuccessful();
        // Even a request that looks like a click: the embed is not a button.
        $this->client->request('GET', '/d/'.$sent['token'].'/pdf', server: self::CLICK);
        $this->client->request('GET', '/d/'.$sent['token'].'/open', server: self::EMBED);

        self::assertSame(['first_read_at' => null, 'read_count' => 0], $this->readState($sent['send']));
    }

    public function testAPrefetchNeverCounts(): void
    {
        $sent = $this->sentDocument();

        foreach ([['HTTP_SEC_PURPOSE' => 'prefetch'], ['HTTP_SEC_PURPOSE' => 'prefetch;prerender'], ['HTTP_PURPOSE' => 'prefetch'], ['HTTP_X_MOZ' => 'prefetch'], ['HTTP_SEC_FETCH_USER' => '?0']] as $extra) {
            $this->client->request('GET', '/d/'.$sent['token'].'/open', server: $extra + self::CLICK);
            self::assertResponseIsSuccessful();
        }

        self::assertSame(['first_read_at' => null, 'read_count' => 0], $this->readState($sent['send']));
    }

    public function testTheDownloadButtonCounts(): void
    {
        $sent = $this->sentDocument();

        $this->client->request('GET', '/d/'.$sent['token'].'/download', server: self::CLICK);

        self::assertSame(['first_read_at' => '2026-11-03 10:00:00', 'read_count' => 1], $this->readState($sent['send']));
    }

    public function testTheGonePageSpeaksTheBrowsersLanguage(): void
    {
        $this->client->request('GET', '/d/'.str_repeat('A', 43), server: ['HTTP_ACCEPT_LANGUAGE' => 'en-GB,en;q=0.9']);
        self::assertSelectorTextContains('h1', 'This link is not available');
        self::assertSame('en', $this->client->getCrawler()->filter('html')->attr('lang'));

        $this->client->request('GET', '/d/'.str_repeat('A', 43), server: ['HTTP_ACCEPT_LANGUAGE' => 'cs-CZ,cs;q=0.9,en;q=0.8']);
        self::assertSelectorTextContains('h1', 'Odkaz není k dispozici');

        $this->client->request('GET', '/d/'.str_repeat('A', 43), server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR']);
        self::assertSelectorTextContains('h1', 'Odkaz není k dispozici');
    }

    public function testThePaymentBlockShowsWhatThePdfShows(): void
    {
        $sent = $this->sentDocument();

        $crawler = $this->client->request('GET', '/d/'.$sent['token']);

        $payment = $crawler->filter('section[aria-labelledby="payment-title"]');
        self::assertSame('Platba převodem', $payment->filter('h2')->text());
        $values = $payment->filter('dd')->each(static fn ($dd): string => $dd->text());
        self::assertSame(['19-2000145399/0800', 'CZ65 0800 0000 1920 0014 5399', '20260001'], \array_slice($values, 0, 3));
        self::assertMatchesRegularExpression('/^1\x{a0}210,00\x{a0}Kč$/u', $values[3]);
        self::assertCount(1, $payment->filter('figure svg'), 'the QR code is drawn inline');
        self::assertGreaterThan(100, $payment->filter('figure svg rect')->count());
        $code = $this->connection()->fetchOne('SELECT payment_code FROM invoice_send WHERE id = ?', [$sent['send']]);
        self::assertIsString($code);
        self::assertStringStartsWith('SPD*1.0*ACC:CZ6508000000192000145399*AM:1210.00*CC:CZK*DT:20261115*MSG:20260001*X-VS:20260001', $code);
        self::assertStringNotContainsString($code, (string) $this->client->getResponse()->getContent(), 'drawn, not printed');
    }

    public function testCashOrCardShowsNoPaymentBlock(): void
    {
        foreach ([PaymentMethod::Cash, PaymentMethod::Card] as $method) {
            $sent = $this->sentDocument(method: $method);

            $this->client->request('GET', '/d/'.$sent['token']);

            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('#payment-title');
            self::assertSelectorNotExists('svg rect');
        }
    }

    public function testASameOriginPdfFetchSetsReadOnceAndCounts(): void
    {
        $sent = $this->sentDocument();
        self::mockTime('2026-11-04 08:30:00');

        $this->client->request('GET', '/d/'.$sent['token'].'/open', server: self::CLICK);
        self::assertResponseIsSuccessful();
        self::mockTime('2026-11-05 09:00:00');
        $this->client->request('GET', '/d/'.$sent['token'].'/open', server: self::CLICK);

        self::assertSame(['first_read_at' => '2026-11-04 08:30:00', 'read_count' => 2], $this->readState($sent['send']));
    }

    public function testTheCopyLinkNeverCounts(): void
    {
        $sent = $this->sentDocument(copy: true);
        self::assertNotNull($sent['copyToken']);

        $this->client->request('GET', '/d/'.$sent['copyToken']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/d/'.$sent['copyToken'].'/open', server: self::CLICK);
        self::assertResponseIsSuccessful();
        self::assertSame($this->storedPdf($sent['send']), $this->client->getInternalResponse()->getContent());

        self::assertSame(['first_read_at' => null, 'read_count' => 0], $this->readState($sent['send']));
    }

    public function testASignedInMemberNeverCounts(): void
    {
        $sent = $this->sentDocument();
        $this->signedInMemberOf([$this->tenant]);

        $this->client->request('GET', '/d/'.$sent['token'].'/open', server: self::CLICK);

        self::assertResponseIsSuccessful();
        self::assertSame(['first_read_at' => null, 'read_count' => 0], $this->readState($sent['send']));
    }

    public function testAnotherTenantsMemberStillCounts(): void
    {
        $sent = $this->sentDocument();
        $this->signedInMemberOf([$this->browser->fixtures->tenant('Tenant B')]);

        $this->client->request('GET', '/d/'.$sent['token'].'/open', server: self::CLICK);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->readState($sent['send'])['read_count']);
    }

    public function testASignedInUserOfAnotherTenantKeepsTheirActiveTenant(): void
    {
        $sent = $this->sentDocument();
        $second = $this->browser->fixtures->tenant('Tenant C');
        $this->signedInMemberOf([$this->browser->fixtures->tenant('Tenant B'), $second]);
        $choice = $this->client->request('GET', '/organisation/choose');
        $this->client->submit($choice->filter('form[action$="'.$second->toString().'"]')->form());

        $this->client->request('GET', '/d/'.$sent['token']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/d/'.$sent['token'].'/open', server: self::CLICK);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Tenant C');
    }

    public function testAUserWithoutAChosenTenantIsNotRedirected(): void
    {
        $sent = $this->sentDocument();
        $this->signedInMemberOf([$this->browser->fixtures->tenant('Tenant B'), $this->browser->fixtures->tenant('Tenant C')]);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/organisation/choose');

        $this->client->request('GET', '/d/'.$sent['token']);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/d/'.$sent['token'].'/pdf');
        self::assertResponseIsSuccessful();
    }

    public function testNoAuditEntryIsWritten(): void
    {
        $sent = $this->sentDocument();
        $before = $this->auditEntries();

        $this->client->request('GET', '/d/'.$sent['token']);
        $this->client->request('GET', '/d/'.$sent['token'].'/open', server: self::CLICK);

        self::assertSame(1, $this->readState($sent['send'])['read_count']);
        self::assertSame($before, $this->auditEntries());
    }

    public function testTooManyRequestsAre429(): void
    {
        $sent = $this->sentDocument();
        self::getContainer()->get('test.document_link_limiter')->create('127.0.0.1')->consume(59);

        $this->client->request('GET', '/d/'.$sent['token']);
        self::assertResponseIsSuccessful();
        foreach (['/d/'.$sent['token'], '/d/'.$sent['token'].'/pdf'] as $path) {
            $this->client->request('GET', $path, server: self::CLICK);
            self::assertResponseStatusCodeSame(429, $path);
            self::assertStringNotContainsString('20260001', (string) $this->client->getResponse()->getContent());
        }
        self::assertSame(0, $this->readState($sent['send'])['read_count']);
    }

    public function testRevokeCommandEndsTheLink(): void
    {
        $sent = $this->sentDocument();

        $tester = $this->command($sent['send']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('ended', $tester->getDisplay());
        $revoked = $this->connection()->fetchOne('SELECT revoked_at FROM invoice_send WHERE id = ?', [$sent['send']]);
        self::assertSame('2026-11-03 10:00:00', substr(\is_string($revoked) ? $revoked : '', 0, 19));
        $this->client->request('GET', '/d/'.$sent['token']);
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->context->has(), 'the command leaves no tenant active');

        self::assertSame(1, $this->command('01963c5e-0000-7000-8000-00000000000a')->getStatusCode());
    }

    /**
     * A delivered invoice of tenant A, sent from its firm.
     *
     * @return array{send: string, invoice: string, token: string, copyToken: ?string}
     */
    private function sentDocument(DocumentLanguage $language = DocumentLanguage::Czech, bool $copy = false, PaymentMethod $method = PaymentMethod::BankTransfer): array
    {
        $sent = $this->queuedDocument($language, $copy, $method);
        $this->inTenant(static function (): void {
            $mail = self::getContainer()->get('messenger.transport.mail');
            self::assertInstanceOf(InMemoryTransport::class, $mail);
            new RunMessages($mail, self::getContainer()->get(MessageBusInterface::class))->run();
        });

        return $sent;
    }

    /**
     * @return array{send: string, invoice: string, token: string, copyToken: ?string}
     */
    private function queuedDocument(DocumentLanguage $language = DocumentLanguage::Czech, bool $copy = false, PaymentMethod $method = PaymentMethod::BankTransfer): array
    {
        return $this->inTenant(function () use ($language, $copy, $method): array {
            $container = self::getContainer();
            [$firm, $series, $customer] = $this->firm ??= $this->newFirm();
            $day = new \DateTimeImmutable('2026-11-01');
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
            $invoice = $container->get(IssueInvoice::class)->issue(new InvoiceInput($firm, $series, null, $customer, null, $day, $day, new \DateTimeImmutable('2026-11-15'), $method, PaymentMethod::BankTransfer === $method ? self::IBAN : null, '20260001', '', '', 'Consulting', '', '', $language, [$line]));
            $send = $container->get(SendInvoice::class)->send(new SendRequest($invoice->id(), ['billing@example.com'], [], $copy, 'Invoice {number}', "Invoice {number}\n{link}"));
            $tokens = $container->get(LinkTokens::class);

            return [
                'send' => $send->id(),
                'invoice' => $invoice->id(),
                'token' => $tokens->token($send->id(), LinkAudience::Customer),
                'copyToken' => $copy ? $tokens->token($send->id(), LinkAudience::Copy) : null,
            ];
        });
    }

    /**
     * A payer firm with a series, a mail server and a contact address, and a
     * customer, in the active tenant.
     *
     * @return array{string, string, string} the ids of the firm, the series and the customer
     */
    private function newFirm(): array
    {
        $container = self::getContainer();
        $em = $this->browser->em();
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1', [self::IBAN]);
        $em->persist($firm);
        $series = new Series($firm, 'Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $em->persist($series);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $em->persist($customer);
        $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $em->persist(FirmMailServer::define($firm, 'smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $container->get(MailSecrets::class)->seal('correct-horse-battery'), 'invoices@example.com', 'Example Firm'));
        $em->flush();
        $settings = $container->get(FirmInvoicingSettings::class);
        $own = $settings->for($firm);
        $own->change($own->rounding(), '', '', '', 'office@example.com', '', '');
        $settings->save($own);
        $em->flush();

        return [$firm->id(), $series->id(), $customer->id()];
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function inTenant(callable $work): mixed
    {
        $this->context->switchTo($this->tenant);
        try {
            return $work();
        } finally {
            $this->context->reset();
        }
    }

    /**
     * @param list<TenantId> $tenants
     */
    private function signedInMemberOf(array $tenants): void
    {
        $user = $this->browser->fixtures->user();
        foreach ($tenants as $tenant) {
            $this->browser->fixtures->join($user, $tenant, Role::Owner);
        }
        $this->browser->signIn();
        self::assertSame(IdentityFixtures::EMAIL, $user->email());
    }

    private function command(string $sendId): CommandTester
    {
        $kernel = self::$kernel ?? throw new \LogicException('The kernel is not booted.');
        $tester = new CommandTester(new Application($kernel)->find('app:invoice:revoke-link'));
        $tester->execute(['sendId' => $sendId]);

        return $tester;
    }

    /**
     * @return array{first_read_at: ?string, read_count: int}
     */
    private function readState(string $sendId): array
    {
        $row = $this->connection()->fetchAssociative('SELECT first_read_at, read_count FROM invoice_send WHERE id = ?', [$sendId]);
        self::assertIsArray($row);
        $first = $row['first_read_at'];
        $count = $row['read_count'];
        self::assertIsNumeric($count);

        return ['first_read_at' => \is_string($first) ? substr($first, 0, 19) : null, 'read_count' => (int) $count];
    }

    private function pdfPath(string $sendId): string
    {
        $key = $this->connection()->fetchOne('SELECT pdf_key FROM invoice_send WHERE id = ?', [$sendId]);
        self::assertIsString($key);

        return self::getContainer()->getParameter('app.sent_documents_dir').'/'.$key;
    }

    private function storedPdf(string $sendId): string
    {
        $bytes = file_get_contents($this->pdfPath($sendId));
        self::assertIsString($bytes);
        self::assertStringStartsWith('%PDF-', $bytes);

        return $bytes;
    }

    private function auditEntries(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM audit_entry');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
