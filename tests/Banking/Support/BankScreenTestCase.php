<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Support;

use App\Banking\Application\GenerateAddress;
use App\Banking\Application\MovementInput;
use App\Banking\Application\PairMovement;
use App\Banking\Application\ProcessMessage;
use App\Banking\Application\ReceiveInboundMail;
use App\Banking\Application\ReceiveOutcome;
use App\Banking\Application\RecordMovement;
use App\Banking\Application\TrustVerdict;
use App\Banking\Domain\InboundChannel;
use App\Banking\Domain\MessageTrust;
use App\Banking\Domain\MovementDirection;
use App\Identity\Domain\Role;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
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
use App\Party\Domain\FirmBankAccount;
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
use App\Tests\Party\Support\FirmAccounts;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A signed-in tenant with a payer firm, its account on the test IBAN, a series
 * and a customer, on 20 November 2026 at noon UTC; movements and bank mail are
 * recorded through the application, as the bank screens would find them.
 */
abstract class BankScreenTestCase extends WebTestCase
{
    protected const string IBAN = 'CZ6508000000192000145399';
    protected const string OTHER_IBAN = 'CZ5508000000001234567899';
    protected const string COUNTER_IBAN = 'CZ1208000000009876543210';
    protected const array DIALOG = ['HTTP_X_FORESI_DIALOG' => '1', 'HTTP_ACCEPT' => 'application/json, text/html'];
    private const string KB_FIXTURES = __DIR__.'/../../Fixtures/Banking/kb/';
    private const string AUTH_FIXTURES = __DIR__.'/../../Fixtures/Banking/auth/';

    protected KernelBrowser $client;
    protected SignedInBrowser $browser;
    protected TenantContext $context;
    protected TenantId $tenant;
    protected LegalEntity $firm;
    protected FirmBankAccount $account;
    protected Series $series;
    protected Party $customer;
    /** @var array<string, string> by account id */
    private array $addresses = [];

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-20 12:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, $this->em(), $this->context);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    protected function signedIn(Role $role = Role::Owner): void
    {
        [$this->tenant] = $this->browser->seed(['Tenant A'], role: $role);
        $this->scoped(function (): void {
            [$this->firm, $this->account, $this->series, $this->customer] = $this->firmWithSeries('Example Firm s.r.o.', self::IBAN);
        });
        $this->browser->signIn();
    }

    /**
     * @return array{LegalEntity, FirmBankAccount, Series, Party}
     */
    protected function firmWithSeries(string $name, string $iban, string $customer = 'Example Customer a.s.'): array
    {
        // A second firm of a tenant needs a number of its own.
        $number = 'Example Firm s.r.o.' === $name ? '12345695' : '12345687';
        $firm = new LegalEntity($name, new RegistrationNumber('CZ', $number), VatId::fromString('CZ'.$number), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $this->em()->persist($firm);
        $account = FirmAccounts::add($this->em(), $firm, $iban);
        $series = new Series($firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $series->markDefault();
        $this->em()->persist($series);
        $party = $this->party($customer);

        return [$firm, $account, $series, $party];
    }

    /**
     * A customer with a billing address; call it in the tenant's scope.
     */
    protected function party(string $name): Party
    {
        $party = new Party(PartyKind::Company, $name, new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
        $this->em()->persist($party);
        $this->em()->persist(new PartyAddress($party, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em()->flush();

        return $party;
    }

    /**
     * An invoice of the firm, price without VAT; 1 000.00 is 1 210.00 to pay.
     */
    protected function invoice(string $price = '1000.00', string $vs = '', string $due = '2026-11-15', ?LegalEntity $firm = null, ?Series $series = null, Currency $currency = Currency::CZK, ?Party $customer = null): Invoice
    {
        return $this->scoped(function () use ($price, $vs, $due, $firm, $series, $currency, $customer): Invoice {
            $line = new LineInput('Work', Quantity::fromString('1'), 'h', Money::of($price, $currency), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'));

            return self::getContainer()->get(IssueInvoice::class)->issue(new InvoiceInput(($firm ?? $this->firm)->id(), ($series ?? $this->series)->id(), null, ($customer ?? $this->customer)->id(), null, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable($due), PaymentMethod::BankTransfer, self::IBAN, $vs, '', '', 'Consulting', '', '', DocumentLanguage::Czech, [$line], '' === $vs));
        });
    }

    /**
     * Records a movement as a notice would, and pairs it when the rules leave one answer.
     *
     * @return string its id
     */
    protected function movement(
        string $amount,
        ?string $vs = null,
        MovementDirection $direction = MovementDirection::Incoming,
        MessageTrust $trust = MessageTrust::Unverified,
        ?FirmBankAccount $account = null,
        string $bookedOn = '2026-11-19',
        ?string $counterName = 'Example Remitter Ltd',
        ?string $message = 'Payment for an invoice',
        bool $auto = true,
        ?TenantId $tenant = null,
    ): string {
        return $this->scoped(function () use ($amount, $vs, $direction, $trust, $account, $bookedOn, $counterName, $message, $auto): string {
            $basis = match ($trust) {
                MessageTrust::Verified => TrustVerdict::DMARC,
                MessageTrust::Failed => TrustVerdict::DKIM_FAIL,
                default => TrustVerdict::NO_RESULTS,
            };
            $container = self::getContainer();
            $id = $container->get(RecordMovement::class)->record(new MovementInput(
                ($account ?? $this->account)->id(),
                null,
                $direction,
                Money::of($amount, Currency::CZK),
                new \DateTimeImmutable($bookedOn),
                '123456789/0800',
                self::COUNTER_IBAN,
                $counterName,
                $vs,
                null,
                null,
                $message,
                null,
            ), new TrustVerdict($trust, $basis))->id();
            if ($auto && MovementDirection::Incoming === $direction) {
                $container->get(PairMovement::class)->auto($id);
            }

            return $id;
        }, $tenant);
    }

    /**
     * @return array<string, mixed>
     */
    protected function movementRow(string $id): array
    {
        $this->em()->clear();
        $row = $this->connection()->fetchAssociative('SELECT state, unmatched_reason, payment_id, auto_pair_blocked FROM bank_movement WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * The allocations of payments not cancelled, by invoice id.
     *
     * @return array<string, int> minor units
     */
    protected function liveAllocations(): array
    {
        $this->em()->clear();
        $rows = $this->connection()->fetchAllAssociative('SELECT a.invoice_id, a.amount_minor FROM payment_allocation a JOIN payment p ON p.id = a.payment_id WHERE p.cancelled_at IS NULL ORDER BY a.amount_minor');
        $allocations = [];
        foreach ($rows as $row) {
            $allocations[Scalars::text($row['invoice_id'])] = Scalars::int($row['amount_minor']);
        }

        return $allocations;
    }

    /**
     * An inbound address on the account, and the KB notice of 150.00 CZK with VS 2026001
     * to it, below the topmost Authentication-Results of a trust fixture, or none.
     */
    protected function notice(string $fixture, ?string $auth = 'dmarc-pass', ?FirmBankAccount $account = null, ?string $messageId = null): string
    {
        $address = $this->address($account);
        $raw = $this->fixture(self::KB_FIXTURES.$fixture.'.eml');
        $raw = str_replace("To: bank+token@example.com\n", 'To: '.$address."\n", $raw, $replaced);
        self::assertSame(1, $replaced, 'The fixture is addressed to the placeholder.');
        if (null !== $messageId) {
            $raw = (string) preg_replace('/^Message-ID: <[^>]+>$/m', 'Message-ID: <'.$messageId.'>', $raw, 1);
        }
        if (null === $auth) {
            return $raw;
        }
        $header = strtok($this->fixture(self::AUTH_FIXTURES.$auth.'.eml'), "\n");
        self::assertIsString($header);

        return $header."\n".$raw;
    }

    /**
     * A plain text mail to the inbound address of the account, from someone who is not a bank.
     */
    protected function plainMail(string $subject, string $body, ?FirmBankAccount $account = null): string
    {
        return "From: Someone <someone@example.org>\nTo: ".$this->address($account)."\nSubject: ".$subject."\nMessage-ID: <".bin2hex(random_bytes(6))."@example.org>\nDate: Thu, 19 Nov 2026 10:00:00 +0000\nMIME-Version: 1.0\nContent-Type: text/plain; charset=utf-8\n\n".$body."\n";
    }

    /**
     * The inbound address of the account, generated once.
     */
    protected function address(?FirmBankAccount $account = null): string
    {
        $id = ($account ?? $this->account)->id();

        return $this->addresses[$id] ??= $this->scoped(fn (): string => self::getContainer()->get(GenerateAddress::class)->generate($id));
    }

    /**
     * Stores the mail as the webhook would and, unless told not to, processes it.
     *
     * @return string the id of its row
     */
    protected function received(string $raw, bool $process = true): string
    {
        return $this->scoped(function () use ($raw, $process): string {
            $container = self::getContainer();
            self::assertSame(ReceiveOutcome::Stored, $container->get(ReceiveInboundMail::class)->receive($raw, InboundChannel::Webhook));
            $id = Scalars::text($this->connection()->fetchOne('SELECT id FROM inbound_message WHERE raw_sha256 = ?', [hash('sha256', $raw)]));
            if ($process) {
                $container->get(ProcessMessage::class)->process($id);
            }

            return $id;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function messageRow(string $id): array
    {
        $this->em()->clear();
        $row = $this->connection()->fetchAssociative('SELECT status, failure, parser_id, attempts FROM inbound_message WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    protected function page(string $url): Crawler
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    protected function dialog(string $url): Crawler
    {
        $this->client->request('GET', $url, server: self::DIALOG);

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost/');
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function dialogPost(string $url, array $values): Crawler
    {
        $this->client->request('POST', $url, $values, server: self::DIALOG);

        return new Crawler((string) $this->client->getResponse()->getContent(), 'http://localhost/');
    }

    /**
     * @return array<mixed>
     */
    protected function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    protected function nb(string $text): string
    {
        return str_replace(' Kč', "\u{a0}Kč", $text);
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    protected function scoped(callable $work, ?TenantId $tenant = null): mixed
    {
        $this->context->switchTo($tenant ?? $this->tenant);
        try {
            return $work();
        } finally {
            $this->context->reset();
        }
    }

    protected function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    protected function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function fixture(string $path): string
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        return $raw;
    }
}
