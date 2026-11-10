<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Api;

use App\Identity\Application\ManageApiTokens;
use App\Identity\Domain\ApiScope;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Invoicing\Application\InvoiceInput;
use App\Invoicing\Application\IssueInvoice;
use App\Invoicing\Application\LineInput;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentAllocation;
use App\Invoicing\Domain\PaymentDirection;
use App\Invoicing\Domain\PaymentSource;
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
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Party\Support\FirmAccounts;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A member of an organisation with a personal API token of the read scope, and a
 * firm with a series and a customer to issue and pay invoices with. Data is written
 * straight to the stores while the organisation is the active one; requests sign in
 * with the token, as a client would.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected const string IBAN = 'CZ6508000000192000145399';

    protected KernelBrowser $client;
    protected IdentityFixtures $fixtures;
    protected User $user;
    protected TenantId $tenant;
    protected string $firmId;
    protected string $seriesId;
    protected string $customerId;
    protected string $secret;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The test database lives in memory, so every request must share the kernel.
        $this->client->disableReboot();
        $this->fixtures = new IdentityFixtures($this->em(), $this->context());
        $this->fixtures->rebuildSchema();
        $this->user = $this->fixtures->user();
        $this->tenant = $this->fixtures->tenant('Example Organisation');
        $this->fixtures->join($this->user, $this->tenant, Role::Member);
        [$this->firmId, $this->seriesId, $this->customerId] = $this->inTenant($this->tenant, fn (): array => $this->firmWithCustomer());
        $this->secret = $this->issued();
    }

    /**
     * @param list<ApiScope> $scopes
     */
    protected function issued(array $scopes = [ApiScope::Read]): string
    {
        return $this->inTenant($this->tenant, fn (): string => self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($this->user->id(), 'Reporting', $scopes)->secret);
    }

    /**
     * Runs the work while the organisation is the active one, and flushes it.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    protected function inTenant(TenantId $tenant, \Closure $work): mixed
    {
        $this->context()->switchTo($tenant);
        try {
            $result = $work();
            $this->em()->flush();

            return $result;
        } finally {
            $this->context()->reset();
        }
    }

    /**
     * Another organisation the user is no member of.
     */
    protected function foreignTenant(): TenantId
    {
        return $this->fixtures->tenant('Foreign Organisation');
    }

    /**
     * A firm of the active organisation with a series of invoices and a customer.
     *
     * @return array{string, string, string} the ids of the firm, the series and the customer
     */
    protected function firmWithCustomer(string $firmName = 'Example Firm s.r.o.', string $registrationNumber = '12345695'): array
    {
        $em = $this->em();
        $firm = new LegalEntity($firmName, new RegistrationNumber('CZ', $registrationNumber), VatId::fromString('CZ'.$registrationNumber), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $em->persist($firm);
        FirmAccounts::add($em, $firm, self::IBAN);
        $series = new Series($firm, 'Standard', SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $em->persist($series);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '+420 000 000 000');
        $em->persist($customer);
        $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $em->persist(new PartyEmail($customer, 'billing@example.com', EmailPurpose::Invoices));
        $em->flush();

        return [$firm->id(), $series->id(), $customer->id()];
    }

    /**
     * An invoice of the active organisation, issued as the form would issue it.
     *
     * @param array<string, mixed> $overrides named arguments of InvoiceInput
     */
    protected function invoice(string $issueDate = '2026-11-01', string $due = '2026-11-15', string $price = '1000.00', array $overrides = [], ?string $firmId = null, ?string $seriesId = null, ?string $customerId = null): Invoice
    {
        $input = new \ReflectionClass(InvoiceInput::class)->newInstanceArgs(array_merge([
            'firmId' => $firmId ?? $this->firmId,
            'seriesId' => $seriesId ?? $this->seriesId,
            'typedNumber' => null,
            'customerId' => $customerId ?? $this->customerId,
            'customerOnDocument' => null,
            'issueDate' => new \DateTimeImmutable($issueDate),
            'taxPointDate' => new \DateTimeImmutable($issueDate),
            'dueDate' => new \DateTimeImmutable($due),
            'paymentMethod' => PaymentMethod::BankTransfer,
            'bankAccount' => self::IBAN,
            'variableSymbol' => '20260001',
            'constantSymbol' => '0308',
            'orderNumber' => 'PO-1',
            'description' => 'Consulting',
            'textBefore' => 'Before',
            'textAfter' => 'After',
            'language' => DocumentLanguage::Czech,
            'lines' => [new LineInput('Work', Quantity::fromString('2'), 'h', Money::of($price, Currency::CZK), PriceType::WithoutVat, TaxTreatment::Standard, VatRateKind::Base, null, Percent::fromString('0'))],
        ], $overrides));
        $invoice = self::getContainer()->get(IssueInvoice::class)->issue($input);
        $this->em()->flush();

        return $invoice;
    }

    /**
     * A payment of a firm of the active organisation with its allocations, as a recorded one would be.
     *
     * @param array<string, string> $parts the amount in whole currency units by invoice id; the payment is their sum
     */
    protected function payment(
        string $on = '2026-11-10',
        array $parts = [],
        ?string $firmId = null,
        PaymentDirection $direction = PaymentDirection::Incoming,
        string $note = '',
        ?string $amount = null,
        bool $cancelled = false,
    ): Payment {
        $em = $this->em();
        $total = $amount ?? (string) array_sum(array_map(intval(...), $parts));
        $firm = $em->find(LegalEntity::class, $firmId ?? $this->firmId) ?? throw new \LogicException('No firm.');
        $payment = new Payment($firm, new \DateTimeImmutable($on), Money::of($total, Currency::CZK), PaymentMethod::BankTransfer, PaymentSource::Manual, null, $note, null, new \DateTimeImmutable('2026-11-10 12:00:00'), $direction);
        $em->persist($payment);
        foreach ($parts as $invoiceId => $part) {
            $em->persist(new PaymentAllocation($payment, $em->getReference(Invoice::class, $invoiceId) ?? throw new \LogicException('No invoice.'), Money::of($part, Currency::CZK)));
        }
        if ($cancelled) {
            $payment->cancel(new \DateTimeImmutable('2026-11-11 08:00:00'), $this->user->id());
        }
        $em->flush();

        return $payment;
    }

    /**
     * @param array<string, string> $query
     */
    protected function get(string $path, array $query = [], ?string $secret = null): void
    {
        $this->client->request('GET', $path, $query, server: ['HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->secret)]);
    }

    /**
     * @return array<mixed>
     */
    protected function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /**
     * A value of the answer, by the keys that lead to it: at('data', 0, 'id').
     */
    protected function at(string|int ...$keys): mixed
    {
        $value = $this->json();
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @return list<string> the ids of the data of a list
     */
    protected function ids(): array
    {
        self::assertResponseIsSuccessful();
        $data = $this->json()['data'];
        self::assertIsArray($data);

        return array_values(array_map(static fn (mixed $item): string => \is_array($item) && \is_string($item['id'] ?? null) ? $item['id'] : throw new \LogicException('An item has no id.'), $data));
    }

    /**
     * Pages through a list with the given limit, and answers the ids of each page.
     *
     * @param array<string, string> $query
     *
     * @return list<list<string>>
     */
    protected function pages(string $path, int $limit, array $query = []): array
    {
        $pages = [];
        $cursor = null;
        do {
            $this->get($path, $query + ['limit' => (string) $limit] + (null === $cursor ? [] : ['cursor' => $cursor]));
            $pages[] = $this->ids();
            $cursor = $this->json()['next_cursor'];
            self::assertTrue(null === $cursor || \is_string($cursor));
        } while (null !== $cursor && \count($pages) < 10);

        return $pages;
    }

    /**
     * @param array<string, mixed> $extensions the members a problem of this kind adds
     */
    protected function assertProblem(int $status, string $code, array $extensions = []): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        $problem = $this->json();
        self::assertSame('about:blank', $problem['type']);
        self::assertSame($status, $problem['status']);
        self::assertSame($code, $problem['code']);
        self::assertIsString($problem['detail']);
        self::assertNotSame('', $problem['detail']);
        foreach ($extensions as $name => $value) {
            self::assertSame($value, $problem[$name] ?? null, $name);
        }
    }

    protected function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    protected function context(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
