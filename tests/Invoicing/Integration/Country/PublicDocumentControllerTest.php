<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Doctrine\DBAL\Connection;
use Foresi\Invoicing\Application\AdvanceDocuments;
use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\CorrectionInput;
use Foresi\Invoicing\Application\DeductibleAdvances;
use Foresi\Invoicing\Application\DocumentFormat;
use Foresi\Invoicing\Application\FirmInvoicingSettings;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\LinkTokens;
use Foresi\Invoicing\Application\MailSecrets;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Application\SendInvoice;
use Foresi\Invoicing\Application\SendRequest;
use Foresi\Invoicing\Application\TrashInvoice;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\CorrectionType;
use Foresi\Invoicing\Domain\FirmMailServer;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\LinkAudience;
use Foresi\Invoicing\Domain\MailEncryption;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Tests\Support\Mail\RecordingMailGateway;
use Foresi\Tests\Support\Messenger\RunMessages;
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

    /** @var array{string, string, string}|null */
    private ?array $firm = null;

    protected function setUp(): void
    {
        self::mockTime('2026-11-03 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        // A Czech browser, unless a test says otherwise.
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'cs-CZ,cs;q=0.9');
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
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
        $this->scoped(function () use ($sent): void {
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
        $this->scoped(static function () use ($cancelled): void {
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
            // The headers of every response, which the link does not set itself.
            self::assertSame('same-origin', $headers->get('Cross-Origin-Opener-Policy'), $path);
            self::assertStringContainsString('camera=()', (string) $headers->get('Permissions-Policy'), $path);
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

    public function testThePublicPageSaysPaidWhenPaid(): void
    {
        $sent = $this->sentDocument();

        $this->client->request('GET', '/d/'.$sent['token']);
        self::assertSelectorNotExists('[data-paid-status]', 'nothing is paid yet');

        $this->paid($sent['invoice'], '500.00');
        $this->client->request('GET', '/d/'.$sent['token']);
        self::assertSelectorNotExists('[data-paid-status]', 'a part payment says nothing to the customer');

        $this->paid($sent['invoice'], '710.00');
        $this->client->request('GET', '/d/'.$sent['token']);
        self::assertSelectorTextSame('[data-paid-status]', 'Uhrazeno');

        $this->paid($sent['invoice'], '100.00');
        $this->client->request('GET', '/d/'.$sent['token']);
        self::assertSelectorTextSame('[data-paid-status]', 'Uhrazeno', 'overpaid is paid to the customer');
    }

    public function testAPaidOrOverpaidInvoiceInvitesNoPayment(): void
    {
        $sent = $this->sentDocument();

        $this->paid($sent['invoice'], '1210.00');
        $this->client->request('GET', '/d/'.$sent['token']);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#payment-title', 'paid: no payment instructions');
        self::assertSelectorNotExists('figure svg', 'paid: no QR code');
        self::assertSelectorNotExists('[data-remaining]');

        $this->paid($sent['invoice'], '100.00');
        $this->client->request('GET', '/d/'.$sent['token']);
        self::assertSelectorNotExists('#payment-title', 'overpaid: no payment instructions');
        self::assertSelectorNotExists('figure svg', 'overpaid: no QR code');
    }

    /**
     * The PDF still shows the payment details it was sent with, so the page says,
     * next to the download, that there is nothing left to pay.
     */
    public function testAPaidInvoiceSaysWhenItWasPaidNextToTheUnchangedPdf(): void
    {
        $sent = $this->sentDocument();
        $stored = $this->storedPdf($sent['send']);

        $crawler = $this->client->request('GET', '/d/'.$sent['token']);
        self::assertSelectorNotExists('[data-paid-note]');
        self::assertSame('Stáhnout', $crawler->filter('a[href$="/download"]')->text());

        $this->paid($sent['invoice'], '500.00', '2026-11-01');
        $this->client->request('GET', '/d/'.$sent['token']);
        self::assertSelectorNotExists('[data-paid-note]', 'a part payment leaves something to pay');

        $this->paid($sent['invoice'], '710.00', '2026-11-02');
        $crawler = $this->client->request('GET', '/d/'.$sent['token']);

        $date = self::getContainer()->get(DocumentFormat::class)->date(new \DateTimeImmutable('2026-11-02'), DocumentLanguage::Czech);
        self::assertSame('Uhrazeno '.$date.', není co platit', $crawler->filter('[data-paid-note]')->text(), 'the day the last payment settled it');
        self::assertSame('Stáhnout fakturu (uhrazená)', $crawler->filter('a[href$="/download"]')->text());
        foreach (['/pdf', '/open', '/download'] as $suffix) {
            $this->client->request('GET', '/d/'.$sent['token'].$suffix);
            self::assertSame($stored, $this->client->getInternalResponse()->getContent(), 'the PDF is the one sent');
        }
    }

    public function testAPartPaymentShowsWhatRemainsWithoutTheQrCode(): void
    {
        $sent = $this->sentDocument();
        $this->paid($sent['invoice'], '500.00');

        $crawler = $this->client->request('GET', '/d/'.$sent['token']);

        self::assertMatchesRegularExpression('/^710,00\x{a0}Kč$/u', $crawler->filter('[data-remaining] dd')->text());
        self::assertSame('Zbývá uhradit', $crawler->filter('[data-remaining] dt')->text());
        $payment = $crawler->filter('section[aria-labelledby="payment-title"]');
        $values = $payment->filter('dd')->each(static fn ($dd): string => $dd->text());
        self::assertSame(['19-2000145399/0800', 'CZ65 0800 0000 1920 0014 5399', '20260001'], \array_slice($values, 0, 3));
        self::assertMatchesRegularExpression('/^710,00\x{a0}Kč$/u', $values[3], 'the transfer asks for what remains');
        // The stored code asks for the whole amount, and the builder takes no other.
        self::assertCount(0, $payment->filter('figure svg'));
    }

    /**
     * A tax document is paid by the payment it taxes, and its PDF says not to pay it,
     * so the page asks for nothing either.
     */
    public function testATaxDocumentSaysItIsPaidAndAsksForNothing(): void
    {
        $sent = $this->sentTaxDocument();

        $crawler = $this->client->request('GET', '/d/'.$sent['token']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Daňový doklad '.$sent['number']);
        self::assertSelectorTextSame('[data-paid-status]', 'Uhrazeno');
        self::assertSelectorTextSame('[data-paid-note]', 'Neplaťte, doklad je již uhrazen');
        self::assertSame(['Celkem uhrazeno'], $crawler->filter('main dl dt')->each(static fn ($dt): string => $dt->text()), 'no amount to pay and no due date');
        self::assertSelectorNotExists('#payment-title');
        self::assertSelectorNotExists('figure svg');
        self::assertSelectorNotExists('[data-remaining]');
        self::assertSame('Stáhnout daňový doklad', $crawler->filter('a[href$="/download"]')->text());
        self::assertSame('Daňový doklad '.$sent['number'].' jako PDF', $crawler->filter('object')->attr('title'));
    }

    public function testAnAdvanceAsksForPaymentAsItsPdfDoes(): void
    {
        $sent = $this->sentAdvance();

        $crawler = $this->client->request('GET', '/d/'.$sent['token']);

        self::assertSelectorTextSame('h1', 'Zálohová faktura '.$sent['number']);
        self::assertSame(['K úhradě', 'Splatnost'], $crawler->filter('main dl dt')->slice(0, 2)->each(static fn ($dt): string => $dt->text()));
        self::assertCount(1, $crawler->filter('section[aria-labelledby="payment-title"] figure svg'), 'its PDF carries the QR code too');
        self::assertSame('Stáhnout', $crawler->filter('a[href$="/download"]')->text());

        $this->paid($sent['invoice'], '1210.00');
        $crawler = $this->client->request('GET', '/d/'.$sent['token']);

        self::assertSelectorNotExists('#payment-title');
        self::assertSame('Stáhnout zálohovou fakturu (uhrazená)', $crawler->filter('a[href$="/download"]')->text());
    }

    /**
     * The advances cover the whole invoice: its PDF says it is settled by them, and so does the page.
     */
    public function testAnInvoiceSettledByAdvancesAsksForNothing(): void
    {
        $sent = $this->sentFinalInvoice('1000.00');

        $crawler = $this->client->request('GET', '/d/'.$sent['token']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Faktura '.$sent['number']);
        self::assertSelectorTextSame('[data-paid-status]', 'Uhrazeno zálohami');
        self::assertSame(['Uhrazeno zálohami, nic nehraďte'], $crawler->filter('main dl dt')->each(static fn ($dt): string => $dt->text()), 'no amount to pay and no due date');
        self::assertMatchesRegularExpression('/^0,00\x{a0}Kč$/u', $crawler->filter('main dl dd')->text());
        self::assertSelectorNotExists('#payment-title');
        self::assertSelectorNotExists('figure svg');
        self::assertSelectorNotExists('[data-remaining]');
    }

    public function testAnInvoiceTheAdvancesOverCoverShowsTheDifferenceToRefund(): void
    {
        $sent = $this->sentFinalInvoice('500.00');

        $crawler = $this->client->request('GET', '/d/'.$sent['token']);

        self::assertSelectorTextSame('[data-paid-status]', 'Uhrazeno zálohami');
        self::assertSame(['Rozdíl k vrácení'], $crawler->filter('main dl dt')->each(static fn ($dt): string => $dt->text()));
        self::assertStringContainsString('605,00', $crawler->filter('main dl dd')->text());
        self::assertSelectorNotExists('#payment-title');
        self::assertSelectorNotExists('figure svg');
    }

    public function testAnInvoiceLeftToPayAfterItsAdvancesStillAsksForIt(): void
    {
        $sent = $this->sentFinalInvoice('3000.00');

        $crawler = $this->client->request('GET', '/d/'.$sent['token']);

        self::assertSelectorNotExists('[data-paid-status]');
        self::assertSame(['K úhradě', 'Splatnost'], $crawler->filter('main dl dt')->slice(0, 2)->each(static fn ($dt): string => $dt->text()));
        self::assertSelectorExists('#payment-title');
    }

    /**
     * A credit note follows its PDF: titled, naming what it corrects and its
     * differences, with what goes back and no way to pay.
     */
    public function testACreditNoteShowsItsParticularsAndAsksForNothing(): void
    {
        $sent = $this->sentCorrection('-1');

        $crawler = $this->client->request('GET', '/d/'.$sent['token']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Opravný daňový doklad '.$sent['number']);
        self::assertSelectorTextSame('[data-correction-kind]', 'dobropis');
        self::assertSelectorTextSame('[data-corrects]', 'Opravuje doklad č. '.$sent['original']);
        self::assertSelectorTextSame('[data-correction-reason]', 'Důvod opravy: Returned goods');
        $differences = $crawler->filter('[data-differences] div')->each(static fn ($row): string => $row->filter('dt')->text().' '.$row->filter('dd')->text());
        self::assertCount(3, $differences);
        self::assertMatchesRegularExpression('/^Rozdíl základu daně -1\x{a0}000,00\x{a0}Kč$/u', $differences[0]);
        self::assertMatchesRegularExpression('/^Rozdíl daně -210,00\x{a0}Kč$/u', $differences[1]);
        self::assertMatchesRegularExpression('/^Rozdíl celkem -1\x{a0}210,00\x{a0}Kč$/u', $differences[2]);
        self::assertSame(['K vrácení'], $crawler->filter('main dl[data-amount] dt')->each(static fn ($dt): string => $dt->text()), 'no due date');
        self::assertMatchesRegularExpression('/^1\x{a0}210,00\x{a0}Kč$/u', $crawler->filter('main dl[data-amount] dd')->text());
        self::assertSelectorTextSame('[data-paid-note]', 'Snižuje částku k úhradě dokladu '.$sent['original'].'. Nic neplaťte.');
        self::assertSelectorNotExists('[data-paid-status]');
        self::assertSelectorNotExists('#payment-title');
        self::assertSelectorNotExists('figure svg');
        self::assertNull($this->connection()->fetchOne('SELECT payment_code FROM invoice_send WHERE id = ?', [$sent['send']]));
    }

    public function testACreditNoteOfAPaidInvoiceSaysTheAmountGoesBack(): void
    {
        $sent = $this->sentCorrection('-1', paidFirst: true);

        $this->client->request('GET', '/d/'.$sent['token']);

        self::assertSelectorTextSame('[data-paid-note]', 'Částku vám vrátíme.');
    }

    public function testTheInvoiceAsksForWhatItsCorrectionsLeave(): void
    {
        $sent = $this->sentCorrection('-0.5');

        $crawler = $this->client->request('GET', '/d/'.$sent['originalToken']);

        self::assertMatchesRegularExpression('/^605,00\x{a0}Kč$/u', $crawler->filter('[data-remaining] dd')->text());
        self::assertSelectorNotExists('figure svg', 'the stored code asks for the whole amount');
    }

    public function testADebitNoteAsksForPaymentWithTheCode(): void
    {
        $sent = $this->sentCorrection('1');

        $crawler = $this->client->request('GET', '/d/'.$sent['token']);

        self::assertSelectorTextSame('h1', 'Opravný daňový doklad '.$sent['number']);
        self::assertSelectorTextSame('[data-correction-kind]', 'vrubopis');
        self::assertSelectorTextSame('[data-corrects]', 'Opravuje doklad č. '.$sent['original']);
        self::assertCount(3, $crawler->filter('[data-differences] div'));
        self::assertSame(['K úhradě', 'Splatnost'], $crawler->filter('main dl[data-amount] dt')->each(static fn ($dt): string => $dt->text()));
        self::assertSelectorNotExists('[data-paid-status]');
        self::assertSelectorNotExists('[data-paid-note]');
        self::assertCount(1, $crawler->filter('section[aria-labelledby="payment-title"] figure svg'));
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
        $this->signedInMember();

        $this->client->request('GET', '/d/'.$sent['token'].'/open', server: self::CLICK);

        self::assertResponseIsSuccessful();
        self::assertSame(['first_read_at' => null, 'read_count' => 0], $this->readState($sent['send']));
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

        self::assertSame(1, $this->command('01963c5e-0000-7000-8000-00000000000a')->getStatusCode());
    }

    /**
     * A delivered invoice, sent from its firm.
     *
     * @return array{send: string, invoice: string, token: string, copyToken: ?string}
     */
    private function sentDocument(DocumentLanguage $language = DocumentLanguage::Czech, bool $copy = false, PaymentMethod $method = PaymentMethod::BankTransfer): array
    {
        $sent = $this->queuedDocument($language, $copy, $method);
        $this->scoped(static function (): void {
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
        return $this->scoped(function () use ($language, $copy, $method): array {
            $container = self::getContainer();
            [, $series, $customer] = $this->firm ??= $this->newFirm();
            $day = new \DateTimeImmutable('2026-11-01');
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
            $invoice = $container->get(IssueInvoice::class)->issue(new InvoiceInput($series, null, $customer, null, $day, $day, new \DateTimeImmutable('2026-11-15'), $method, PaymentMethod::BankTransfer === $method ? self::IBAN : null, '20260001', '', '', 'Consulting', '', '', $language, [$line]));
            $send = $container->get(SendInvoice::class)->send(new SendRequest($invoice->id(), ['billing@example.com'], [], $copy, 'Invoice 20260001', "Invoice 20260001\n{link}"));
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
     * An advance of 1 210 Kč, sent.
     *
     * @return array{send: string, invoice: string, token: string, number: string}
     */
    private function sentAdvance(): array
    {
        $advance = $this->scoped(fn (): Invoice => $this->issuedAdvance());

        return $this->sentOf($advance);
    }

    /**
     * The tax document of a payment of an advance, sent.
     *
     * @return array{send: string, invoice: string, token: string, number: string}
     */
    private function sentTaxDocument(): array
    {
        $document = $this->scoped(function (): Invoice {
            $advance = $this->issuedAdvance();
            $money = Money::of('1210.00', Currency::CZK);
            $payment = self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest(new \DateTimeImmutable('2026-11-02'), $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($advance->id(), $money)]));
            $id = self::getContainer()->get(AdvanceDocuments::class)->taxDocumentOf($advance->id(), $payment->id());
            self::assertNotNull($id);

            return self::getContainer()->get(InvoiceStore::class)->get($id);
        });

        return $this->sentOf($document);
    }

    /**
     * A final invoice of one line without VAT that deducts a paid advance of 1 210 Kč, sent.
     *
     * @return array{send: string, invoice: string, token: string, number: string}
     */
    private function sentFinalInvoice(string $price): array
    {
        $this->sentTaxDocument();
        $final = $this->scoped(function () use ($price): Invoice {
            $container = self::getContainer();
            [, $series, $customer] = $this->firm ?? throw new \LogicException('The firm is missing.');
            $deductible = $container->get(DeductibleAdvances::class)->for($customer, null, DocumentLanguage::Czech);
            self::assertCount(1, $deductible);
            $day = new \DateTimeImmutable('2026-11-03');
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

            return $container->get(IssueInvoice::class)->issue(new InvoiceInput($series, null, $customer, null, $day, $day, new \DateTimeImmutable('2026-11-17'), PaymentMethod::BankTransfer, self::IBAN, '20260001', '', '', 'Consulting', '', '', DocumentLanguage::Czech, [$line, ...$deductible[0]->lines]));
        });

        return $this->sentOf($final);
    }

    /**
     * An invoice of 1 210 Kč and its correction of one line of 1 000 Kč, the quantity given, sent.
     *
     * @return array{send: string, invoice: string, token: string, number: string, original: string, originalToken: string}
     */
    private function sentCorrection(string $quantity, bool $paidFirst = false): array
    {
        $original = $this->queuedDocument();
        if ($paidFirst) {
            $this->paid($original['invoice'], '1210.00');
        }
        $correction = $this->scoped(function () use ($original, $quantity): Invoice {
            [, , $customer] = $this->firm ?? throw new \LogicException('The firm is missing.');
            $day = new \DateTimeImmutable('2026-11-03');
            $line = new LineInput('Work', Quantity::fromString($quantity), 'h', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

            return self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput(null, null, $customer, null, $day, $day, new \DateTimeImmutable('2026-11-17'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Consulting', '', '', DocumentLanguage::Czech, [$line], true, DocumentKind::CorrectionDocument, null, new CorrectionInput($original['invoice'], 'Returned goods', CorrectionType::Change, $day)));
        });

        $number = $this->scoped(static fn (): string => self::getContainer()->get(InvoiceStore::class)->get($original['invoice'])->number());

        return [...$this->sentOf($correction), 'original' => $number, 'originalToken' => $original['token']];
    }

    private function issuedAdvance(): Invoice
    {
        [, , $customer] = $this->firm ??= $this->newFirm();
        $day = new \DateTimeImmutable('2026-11-01');
        $line = new LineInput('Milestone', Quantity::fromString('1'), 'h', Money::of('1000.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

        return self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput(null, null, $customer, null, $day, null, new \DateTimeImmutable('2026-11-15'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Milestone', '', '', DocumentLanguage::Czech, [$line], true, DocumentKind::Advance));
    }

    /**
     * @return array{send: string, invoice: string, token: string, number: string}
     */
    private function sentOf(Invoice $document): array
    {
        return $this->scoped(static function () use ($document): array {
            $container = self::getContainer();
            $send = $container->get(SendInvoice::class)->send(new SendRequest($document->id(), ['billing@example.com'], [], false, 'Document '.$document->number(), "Document {$document->number()}\n{link}"));
            $mail = $container->get('messenger.transport.mail');
            self::assertInstanceOf(InMemoryTransport::class, $mail);
            new RunMessages($mail, $container->get(MessageBusInterface::class))->run();

            return ['send' => $send->id(), 'invoice' => $document->id(), 'token' => $container->get(LinkTokens::class)->token($send->id(), LinkAudience::Customer), 'number' => $document->number()];
        });
    }

    private function paid(string $invoiceId, string $amount, string $on = '2026-11-03'): void
    {
        $this->scoped(function () use ($invoiceId, $amount, $on): void {
            $money = Money::of($amount, Currency::CZK);
            self::getContainer()->get(RecordPayment::class)->record(new PaymentRequest(new \DateTimeImmutable($on), $money, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoiceId, $money)]));
        });
    }

    /**
     * A payer firm with a series, a mail server and a contact address, and a
     * customer.
     *
     * @return array{string, string, string} the ids of the firm, the series and the customer
     */
    private function newFirm(): array
    {
        $container = self::getContainer();
        $em = $this->browser->em();
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $em->persist($firm);
        FirmAccounts::add($em, self::IBAN);
        $series = new Series('Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $em->persist($series);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $em->persist($customer);
        $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $em->persist(FirmMailServer::define('smtp.example.com', 587, MailEncryption::Starttls, 'invoices@example.com', $container->get(MailSecrets::class)->seal('correct-horse-battery'), 'invoices@example.com', 'Example Firm'));
        $em->flush();
        $settings = $container->get(FirmInvoicingSettings::class);
        $own = $settings->get();
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
    private function scoped(callable $work): mixed
    {
        return $work();
    }

    private function signedInMember(): void
    {
        $user = $this->browser->seed();
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
