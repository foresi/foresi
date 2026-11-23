<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
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
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @phpstan-type Form array{lines: list<array<string, string>>, customer: array<string, string>, kind: string, numberPeek: string, seriesId: string, customerId: string, customerQuery: string, issueDate: string, taxPointDate: string, dueDate: string, paymentMethod: string, bankAccount: string, variableSymbol: string, language: string, description: string, orderNumber: string, dueDateSet: string, ...}
 * @phpstan-type Figures array{lines: list<array{total: string, discount: string}>, summaries: list<array{label: string, base: string, vat: string}>, net: string, vat: ?string, rounding: ?string, payable: string}
 */
final class InvoiceControllerTest extends WebTestCase
{
    /** Refused while saving, under the "version" or "form" field, kept for a later step, by the tax document on a payment, or by an import. */
    /** CorrectionRules refuses these, on the form of a corrective tax document only. */
    private const array REFUSED_FOR_CORRECTIONS = ['invoice.problem.correction_kind', 'invoice.problem.correction_link_missing', 'invoice.problem.correction_link_locked', 'invoice.problem.correction_original_invalid', 'invoice.problem.correction_firm', 'invoice.problem.correction_customer', 'invoice.problem.correction_reason_missing', 'invoice.problem.correction_type_missing', 'invoice.problem.correction_decisive_date_missing', 'invoice.problem.correction_tax_point', 'invoice.problem.correction_rate_unknown', 'invoice.problem.correction_over_original', 'invoice.problem.correction_deducted', 'invoice.problem.correction_payment_change'];
    private const array REFUSED_ELSEWHERE = ['invoice.problem.changed_meanwhile', 'invoice.problem.numbers_exhausted', 'invoice.problem.tax_document_exists', 'invoice.problem.no_tax_on_receipt', 'invoice.problem.payment_unknown', 'invoice.problem.already_deducted', 'invoice.problem.import_kind_unsupported', 'invoice.problem.tax_document_with_vat', 'invoice.problem.billed_work_gone'];
    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private LegalEntity $firm;
    private Series $series;
    private Party $customer;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        Clock::set(new MockClock('2026-11-01 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testNoFirmSaysWhatToDo(): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $this->client->request('GET', '/invoices/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Add a firm');
        self::assertSelectorNotExists('form#invoice-form');
    }

    public function testAFirmOfAnUnsupportedCountrySaysSo(): void
    {
        $this->signedIn(address: new Address('Example Street 1', 'Example City', '100 00', 'SK'));

        $this->client->request('GET', '/invoices/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', $this->text('invoice.problem.firm_country_unsupported'));
        self::assertSelectorNotExists('form#invoice-form');
    }

    public function testNextNumberIsAPreviewOfTheSeriesOnThatDate(): void
    {
        $this->signedIn();

        $this->client->request('GET', '/invoices/next-number', ['series' => $this->series->id(), 'date' => '2027-03-01']);

        self::assertResponseIsSuccessful();
        self::assertSame('{"number":"20270001","variableSymbol":"20270001"}', $this->client->getResponse()->getContent());
    }

    public function testNextNumberOfAFirmWithoutASeriesUsesTheDefault(): void
    {
        $this->signedIn(series: false);

        $this->client->request('GET', '/invoices/next-number', ['date' => '2026-11-01']);

        self::assertStringContainsString('"number":"20260001"', (string) $this->client->getResponse()->getContent());
    }

    public function testNextNumberOfAnUnknownSeriesIs404AndABadDate422(): void
    {
        $this->signedIn();

        $this->client->request('GET', '/invoices/next-number', ['series' => '00000000-0000-7000-8000-000000000000', 'date' => '2026-11-01']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/invoices/next-number', ['series' => $this->series->id(), 'date' => 'x']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testEveryTranslatedProblemIsWalked(): void
    {
        $keys = array_column($this->problemsInTheCode(), 1);
        /** @var array{invoice: array{problem: array<string, string>}} $messages */
        $messages = Yaml::parseFile(\dirname(__DIR__, 3).'/src/Invoicing/translations/messages+intl-icu.en.yaml');
        $translated = array_map(static fn (string $key): string => 'invoice.problem.'.$key, array_keys($messages['invoice']['problem']));

        self::assertSame([], array_values(array_diff($translated, $keys, self::REFUSED_ELSEWHERE, self::REFUSED_FOR_CORRECTIONS)));
    }

    public function testNoFirmExplainsWhatIsNeeded(): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $this->client->request('GET', '/invoices/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', $this->text('invoice.blocked.no_firm_title'));
        self::assertSelectorExists('main a[href="/settings/firm"]');
        self::assertSelectorNotExists('#invoice-form');
    }

    public function testOnlyArchivedSeriesExplainWhatIsNeeded(): void
    {
        $this->signedIn();
        $this->scoped(function (): void {
            $this->em()->getRepository(Series::class)->find($this->series->id())?->archive();
            $this->em()->flush();
        });

        $this->client->request('GET', '/invoices/new');

        self::assertSelectorTextContains('main', $this->text('invoice.blocked.no_series_title'));
        self::assertSelectorExists('main a[href="/settings/firm/invoicing"]');
        self::assertSelectorNotExists('#invoice-form');
    }

    public function testTheNextNumberOfAKindWithoutASeriesComesFromItsStandardSeries(): void
    {
        $this->signedIn();

        $this->client->request('GET', '/invoices/next-number', ['date' => '2026-11-01', 'series' => '', 'kind' => 'advance']);

        self::assertSame(['number' => 'Z920260001', 'variableSymbol' => '920260001'], json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testMoreDetailsStayClosedOnANewForm(): void
    {
        $this->signedIn();

        $this->client->request('GET', '/invoices/new');

        self::assertSelectorNotExists('details[data-more-details][open]');
    }

    public function testAForeignInvoiceIs404(): void
    {
        $this->signedIn();
        $this->client->request('GET', '/invoices/00000000-0000-7000-8000-000000000000/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAGuestGets403(): void
    {
        $this->signedIn(Role::Guest);
        $id = '019a0000-0000-7000-8000-000000000001';

        foreach ([['GET', '/invoices/new'], ['POST', '/invoices/new'], ['GET', '/invoices/'.$id.'/edit'], ['POST', '/invoices/'.$id.'/edit'], ['GET', '/invoices/'.$id.'/copy'], ['POST', '/invoices/recount'], ['GET', '/invoices/next-number?date=2026-11-01'], ['GET', '/invoices/customers']] as [$method, $url]) {
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, $method.' '.$url);
        }
    }

    public function testAMemberWorksWithInvoices(): void
    {
        $this->signedIn(Role::Member);

        $this->client->request('GET', '/invoices/new');

        self::assertResponseIsSuccessful();
    }

    public function testEveryProblemHasItsMessageInBothLanguages(): void
    {
        $found = [];
        preg_match_all('/invoice\.problem\.[a-z_]+/', implode(' ', array_map(file_get_contents(...), array_filter((array) glob(\dirname(__DIR__, 3).'/src/Invoicing/*/*.php'), is_string(...)))).implode(' ', array_map(file_get_contents(...), array_filter((array) glob(\dirname(__DIR__, 3).'/src/Invoicing/*/*/*.php'), is_string(...)))), $found);
        $keys = array_unique($found[0]);
        self::assertGreaterThan(30, \count($keys));

        $translator = self::getContainer()->get('translator');
        foreach (['cs', 'en'] as $locale) {
            foreach ($keys as $key) {
                self::assertNotSame($key, $translator->trans($key, locale: $locale), $locale.' '.$key);
            }
        }
    }

    /**
     * Runs outside a request.
     *
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

    private function signedIn(Role $role = Role::Owner, VatStatus $status = VatStatus::Registered, bool $series = true, ?Address $address = null): void
    {
        $this->browser->seed(role: $role);
        $vatId = VatStatus::Registered === $status ? VatId::fromString('CZ12345695') : null;
        $this->firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), $vatId, $address ?? new Address('Example Street 1', 'Example City', '100 00', 'CZ'), $status, 'Example register, entry C 1');
        $this->em()->persist($this->firm);
        FirmAccounts::add($this->em(), self::IBAN);
        if ($series) {
            $this->series = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
            $this->series->markDefault();
            $this->em()->persist($this->series);
        }
        $this->customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '+420 000 000 000');
        $this->em()->persist($this->customer);
        $this->em()->persist(new PartyAddress($this->customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em()->persist(new PartyEmail($this->customer, 'billing@example.com', EmailPurpose::Invoices));
        $this->em()->flush();
        $this->browser->signIn();
    }

    /**
     * The fields and keys of every problem in the source, a line's standing for all of them.
     *
     * @return list<array{string, string}>
     */
    private function problemsInTheCode(): array
    {
        $source = \dirname(__DIR__, 3).'/src/Invoicing';
        $code = (string) file_get_contents($source.'/Application/IssueRules.php');
        $code .= (string) file_get_contents($source.'/Application/LineRules.php');
        $code .= (string) file_get_contents($source.'/Application/EditInvoice.php');
        $code .= (string) file_get_contents($source.'/Application/NumberAllocator.php');
        $reader = (string) file_get_contents($source.'/Infrastructure/Form/InvoiceFormReader.php');

        $problems = [];
        preg_match_all("/IssueProblem\\((?:'([a-zA-Z]+)'|\\\$field(?:\\.'\\.([a-zA-Z]+)')?), 'invoice\\.problem\\.([a-z_]+)'/", $code, $found, \PREG_SET_ORDER);
        foreach ($found as [, $field, $cell, $key]) {
            $problems[] = ['' !== $field ? $field : 'lines.0.'.('' !== $cell ? $cell : 'text'), 'invoice.problem.'.$key];
        }
        preg_match_all("/\\\$errors\\[(?:'([a-zA-Z.]+)'|\\\$field(?:\\.'([a-zA-Z]+)')?)\\] = 'invoice\\.problem\\.([a-z_]+)'/", $reader, $found, \PREG_SET_ORDER);
        foreach ($found as [, $field, $cell, $key]) {
            $problems[] = ['' !== $field ? $field : ('' !== $cell ? 'lines.0.'.$cell : 'issueDate'), 'invoice.problem.'.$key];
        }

        return $problems;
    }

    private function text(string $key): string
    {
        return self::getContainer()->get(TranslatorInterface::class)->trans($key, locale: 'en');
    }

    private function em(): EntityManagerInterface
    {
        return $this->browser->em();
    }
}
