<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Identity\Domain\Role;
use App\Invoicing\Application\InvoiceBundle;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\Quantity;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRateKind;
use App\Party\Application\LegalEntities;
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
use App\Tests\Support\PdfTools;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class InvoiceExportControllerTest extends WebTestCase
{
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
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheFormOffersThePreviousMonth(): void
    {
        $this->signedIn();

        $crawler = $this->client->request('GET', '/invoices/export');

        self::assertResponseIsSuccessful();
        self::assertSame('2026-10-01', $crawler->filter('input[name="from"]')->attr('value'));
        self::assertSame('2026-10-31', $crawler->filter('input[name="to"]')->attr('value'));
        self::assertSame('false', $crawler->filter('form[action="/invoices/export"]')->attr('data-turbo'));
        self::assertSame($this->firm->id(), $crawler->filter('select[name="firm"] option[selected]')->attr('value'));
    }

    public function testTheListLinksToIt(): void
    {
        $this->signedIn();
        $this->issue('2026-10-05');

        $crawler = $this->client->request('GET', '/invoices');

        self::assertSame('For the accountant', trim($crawler->filter('main a[href="/invoices/export"]')->text()));
    }

    public function testTheZipHoldsOneIsdocPdfPerInvoice(): void
    {
        $this->signedIn();
        $first = $this->issue('2026-10-05');
        $second = $this->issue('2026-10-06');

        $files = $this->download('2026-10-01', '2026-10-31');

        self::assertSame([$first->number().'-isdoc.pdf', $second->number().'-isdoc.pdf'], array_keys($files));
        $headers = $this->client->getResponse()->headers;
        self::assertSame('application/zip', $headers->get('Content-Type'));
        self::assertStringStartsWith('attachment; filename=invoices-12345695-20261001-20261031.zip', (string) $headers->get('Content-Disposition'));
        foreach ($files as $pdf) {
            self::assertStringStartsWith('%PDF-', $pdf);
            self::assertSame('invoice.isdoc', PdfTools::attachment($pdf)['name']);
        }
    }

    public function testOnlyThatFirmAndPeriod(): void
    {
        $this->signedIn();
        $inside = $this->issue('2026-10-05');
        $this->issue('2026-09-30');
        $this->issue('2026-11-01');
        $other = $this->otherFirm();
        $this->issue('2026-10-05', $other);

        self::assertSame([$inside->number().'-isdoc.pdf'], array_keys($this->download('2026-10-01', '2026-10-31')));
    }

    public function testTheTaxPointDecidesThePeriod(): void
    {
        $this->signedIn();
        $inside = $this->issue('2026-11-02', taxPoint: '2026-10-31');
        $this->issue('2026-10-02', taxPoint: '2026-11-01');

        self::assertSame([$inside->number().'-isdoc.pdf'], array_keys($this->download('2026-10-01', '2026-10-31')));
    }

    public function testBoundaryDaysAreIncluded(): void
    {
        $this->signedIn();
        $first = $this->issue('2026-10-01');
        $last = $this->issue('2026-10-31');

        self::assertSame([$first->number().'-isdoc.pdf', $last->number().'-isdoc.pdf'], array_keys($this->download('2026-10-01', '2026-10-31')));
    }

    public function testTrashIsLeftOut(): void
    {
        $this->signedIn();
        $kept = $this->issue('2026-10-05');
        $trashed = $this->issue('2026-10-06');
        $this->scoped(fn (): Invoice => self::getContainer()->get(TrashInvoice::class)->trash($trashed->id(), 'Wrong customer'));

        self::assertSame([$kept->number().'-isdoc.pdf'], array_keys($this->download('2026-10-01', '2026-10-31')));
    }

    public function testAnEmptyPeriodSaysSoAndGivesNoFile(): void
    {
        $this->signedIn();

        $crawler = $this->post(['from' => '2026-10-01', 'to' => '2026-10-31']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('text/html', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('No invoices', $crawler->filter('main form [role="status"]')->text());
    }

    public function testALongerPeriodIsAFormError(): void
    {
        $this->signedIn();
        $this->issue('2026-10-05');

        $this->post(['from' => '2025-10-01', 'to' => '2026-10-02']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#export-to-error', '366');
    }

    public function testAPeriodOf366DaysPasses(): void
    {
        $this->signedIn();
        $this->issue('2026-10-05');

        self::assertCount(1, $this->download('2025-10-05', '2026-10-05'));
    }

    public function testAPeriodMustRunForwardAndHaveDates(): void
    {
        $this->signedIn();

        foreach ([['from' => '2026-10-31', 'to' => '2026-10-01'], ['from' => '', 'to' => '2026-10-31'], ['from' => '2026-02-31', 'to' => '2026-10-31'], ['from' => '2026-10-01', 'to' => 'soon']] as $period) {
            $this->post($period);
            self::assertResponseStatusCodeSame(422);
        }
    }

    public function testAnotherTenantsFirmIs404(): void
    {
        $this->signedIn();
        $foreign = $this->foreignFirm();

        $this->post(['firm' => $foreign->id(), 'from' => '2026-10-01', 'to' => '2026-10-31']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAMissingFirmIs404(): void
    {
        $this->signedIn();

        $this->post(['firm' => 'nothing', 'from' => '2026-10-01', 'to' => '2026-10-31']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testASubcontractorGets403(): void
    {
        $this->signedIn(Role::Client);

        $this->client->request('GET', '/invoices/export');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/invoices/export');
        self::assertResponseStatusCodeSame(403);
    }

    public function testADownloadNeedsAToken(): void
    {
        $this->signedIn();

        $this->client->request('POST', '/invoices/export', ['_token' => 'wrong', 'firm' => $this->firm->id(), 'from' => '2026-10-01', 'to' => '2026-10-31']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testADocumentThatCannotBeDrawnStopsTheDownloadAndIsNamed(): void
    {
        $this->signedIn();
        $good = $this->issue('2026-10-05');
        $bad = $this->issue('2026-10-06');
        $this->scoped(fn (): int => (int) $this->em()->getConnection()->executeStatement("UPDATE invoice SET issuer_address_country_code = 'XX' WHERE id = :id", ['id' => $bad->id()]));
        $before = $this->temporary();

        $crawler = $this->post(['from' => '2026-10-01', 'to' => '2026-10-31']);

        self::assertResponseStatusCodeSame(422);
        $notice = $crawler->filter('main form [role="status"]')->text();
        self::assertStringContainsString($bad->number(), $notice);
        self::assertStringNotContainsString($good->number(), $notice);
        self::assertSame($before, $this->temporary());
    }

    public function testAFailingArchiveFailsLoudly(): void
    {
        $this->signedIn();
        $this->issue('2026-10-05');
        $path = (string) tempnam(sys_get_temp_dir(), 'foresi-test-');
        // The working directory of the bundle cannot be created over a file.
        touch($path.'.parts');

        try {
            $this->scoped(function () use ($path): void {
                $firm = self::getContainer()->get(LegalEntities::class)->get($this->firm->id());
                $this->expectException(\RuntimeException::class);
                self::getContainer()->get(InvoiceBundle::class)->writeTo($path, $firm, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));
            });
        } finally {
            unlink($path);
            unlink($path.'.parts');
        }
    }

    public function testTheTemporaryFileIsGone(): void
    {
        $this->signedIn();
        $this->issue('2026-10-05');
        $before = $this->temporary();

        self::assertCount(1, $this->download('2026-10-01', '2026-10-31'));

        self::assertSame($before, $this->temporary());
    }

    public function testAnEmptyPeriodLeavesNoTemporaryFile(): void
    {
        $this->signedIn();
        $before = $this->temporary();

        $this->post(['from' => '2026-10-01', 'to' => '2026-10-31']);

        self::assertSame($before, $this->temporary());
    }

    public function testThreeHundredInvoicesStayUnder256Megabytes(): void
    {
        $this->signedIn();
        for ($index = 0; $index < 300; ++$index) {
            $this->issue('2026-10-05');
            $this->em()->clear();
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'foresi-test-');
        [$count, $peak] = $this->scoped(function () use ($path): array {
            $firm = self::getContainer()->get(LegalEntities::class)->get($this->firm->id());
            $base = memory_get_usage();
            memory_reset_peak_usage();
            $count = self::getContainer()->get(InvoiceBundle::class)->writeTo($path, $firm, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'));

            return [$count, memory_get_peak_usage() - $base];
        });
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $entries = $zip->numFiles;
        $zip->close();
        unlink($path);

        self::assertSame(300, $count);
        self::assertSame(300, $entries);
        self::assertLessThan(256 * 1024 * 1024, $peak);
    }

    /**
     * @return array<string, string> what the ZIP holds, by name, in its order
     */
    private function download(string $from, string $to): array
    {
        $this->post(['from' => $from, 'to' => $to]);
        self::assertResponseIsSuccessful();
        $path = (string) tempnam(sys_get_temp_dir(), 'foresi-test-');
        file_put_contents($path, $this->client->getInternalResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $files = [];
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = (string) $zip->getNameIndex($index);
            $files[$name] = (string) $zip->getFromIndex($index);
        }
        $zip->close();
        unlink($path);

        return $files;
    }

    /**
     * @param array<string, string> $values
     */
    private function post(array $values): \Symfony\Component\DomCrawler\Crawler
    {
        $form = $this->client->request('GET', '/invoices/export');
        $token = (string) $form->filter('form[action="/invoices/export"] input[name="_token"]')->attr('value');

        return $this->client->request('POST', '/invoices/export', $values + ['_token' => $token, 'firm' => $this->firm->id()]);
    }

    /**
     * @return list<string>
     */
    private function temporary(): array
    {
        $files = glob(sys_get_temp_dir().'/foresi-bundle-*');

        return false === $files ? [] : $files;
    }

    private function issue(string $issueDate, ?LegalEntity $firm = null, ?string $taxPoint = null): Invoice
    {
        $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of('100.00', Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));
        $input = new InvoiceInput(($firm ?? $this->firm)->id(), $firm instanceof LegalEntity ? null : $this->series->id(), null, $this->customer->id(), null, new \DateTimeImmutable($issueDate), new \DateTimeImmutable($taxPoint ?? $issueDate), new \DateTimeImmutable('2026-12-31'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Work', '', '', DocumentLanguage::Czech, [$line], true);

        return $this->scoped(static fn (): Invoice => self::getContainer()->get(IssueInvoice::class)->issue($input));
    }

    private function otherFirm(): LegalEntity
    {
        return $this->scoped(function (): LegalEntity {
            $firm = new LegalEntity('Example Other s.r.o.', new RegistrationNumber('CZ', '12345679'), VatId::fromString('CZ12345679'), new Address('Example Street 3', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 2', [self::IBAN]);
            $this->em()->persist($firm);
            $this->em()->flush();

            return $firm;
        });
    }

    private function foreignFirm(): LegalEntity
    {
        $tenant = $this->browser->fixtures->tenant('Foreign Tenant '.bin2hex(random_bytes(2)));
        $this->context->switchTo($tenant);
        try {
            $firm = new LegalEntity('Example Foreign s.r.o.', new RegistrationNumber('CZ', '12345679'), null, new Address('Example Street 3', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, 'Register', []);
            $this->em()->persist($firm);
            $this->em()->flush();

            return $firm;
        } finally {
            $this->context->reset();
        }
    }

    private function signedIn(Role $role = Role::Owner): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], role: $role);
        $this->context->switchTo($this->tenant);
        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1', [self::IBAN]);
        $this->em()->persist($this->firm);
        $this->series = new Series($this->firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $this->series->markDefault();
        $this->em()->persist($this->series);
        $this->customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em()->persist($this->customer);
        $this->em()->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em()->flush();
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

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
