<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\Invoice;
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
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class InvoiceListExportControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    /** @var array{firm: LegalEntity, series: Series, customer: Party} */
    private array $world;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAGuestGets403(): void
    {
        $this->signedIn(Role::Guest);

        $this->client->request('POST', '/invoices/list-export', ['kind' => 'invoice', 'format' => 'csv']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testItNeedsAToken(): void
    {
        $this->signedIn();

        $this->client->request('POST', '/invoices/list-export', ['_token' => 'wrong', 'kind' => 'invoice', 'format' => 'csv']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testItIsPostOnly(): void
    {
        $this->signedIn();

        $this->client->request('GET', '/invoices/list-export');

        self::assertResponseStatusCodeSame(405);
    }

    /**
     * @param array<int|string, string> $prices net prices by VAT rate: 21 is the base rate, 12 the reduced one, exempt none
     */
    private function issue(string $issueDate, array $prices): Invoice
    {
        return $this->scoped(fn (): Invoice => $this->issueIn($this->world, $issueDate, $prices));
    }

    /**
     * @param array{firm: LegalEntity, series: Series, customer: Party} $world
     * @param array<int|string, string>                                 $prices
     */
    private function issueIn(array $world, string $issueDate, array $prices): Invoice
    {
        $lines = [];
        foreach ($prices as $rate => $price) {
            $exempt = 'exempt' === $rate;
            $kind = '21' === (string) $rate ? VatRateKind::Base : VatRateKind::Reduced;
            $lines[] = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, $exempt ? TaxTreatment::Exempt : TaxTreatment::Standard, $exempt ? null : $kind, null, Percent::fromString('0'));
        }
        $input = new InvoiceInput($world['series']->id(), null, $world['customer']->id(), null, new \DateTimeImmutable($issueDate), new \DateTimeImmutable($issueDate), new \DateTimeImmutable('2026-12-31'), PaymentMethod::BankTransfer, self::IBAN, '', '', '', 'Work', '', '', DocumentLanguage::Czech, $lines, true);

        return self::getContainer()->get(IssueInvoice::class)->issue($input);
    }

    /**
     * @return array{firm: LegalEntity, series: Series, customer: Party}
     */
    private function world(string $firmName): array
    {
        $firm = new LegalEntity($firmName, new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->em()->persist($firm);
        FirmAccounts::add($this->em(), self::IBAN);
        $series = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $series->markDefault();
        $this->em()->persist($series);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em()->persist($customer);
        $this->em()->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));

        return ['firm' => $firm, 'series' => $series, 'customer' => $customer];
    }

    private function signedIn(Role $role = Role::Owner, string $locale = 'en'): void
    {
        $this->browser->seed($locale, $role);
        $this->world = $this->world('Example Firm s.r.o.');
        $this->em()->flush();
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
        return $work();
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
