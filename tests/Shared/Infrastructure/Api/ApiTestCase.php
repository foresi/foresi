<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Infrastructure\Api;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Application\ManageApiTokens;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\EmailPurpose;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyEmail;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Party\Support\FirmAccounts;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A member of an organisation with a personal API token of the read scope, and a
 * firm with a bank account and a customer. Invoices come from
 * Foresi\Tests\Invoicing\Support\IssuesInvoices, in the tests of modules that
 * have them. Data is written
 * straight to the stores while the organisation is the active one; requests sign in
 * with the token, as a client would.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected const string IBAN = 'CZ6508000000192000145399';

    protected KernelBrowser $client;
    protected IdentityFixtures $fixtures;
    protected User $user;
    protected string $firmId;
    protected string $customerId;
    protected string $secret;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The test database lives in memory, so every request must share the kernel.
        $this->client->disableReboot();
        $this->fixtures = new IdentityFixtures($this->em());
        $this->fixtures->rebuildSchema();
        $this->user = $this->fixtures->user();
        $this->fixtures->join($this->user, Role::Member);
        [$this->firmId, $this->customerId] = $this->flushed(fn (): array => $this->firmWithCustomer());
        $this->secret = $this->issued();
    }

    /**
     * @param list<ApiScope>|null $scopes null for read only
     */
    protected function issued(?array $scopes = null): string
    {
        return $this->flushed(fn (): string => self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($this->user->id(), 'Reporting', $scopes)->secret);
    }

    /**
     * Runs the work and flushes it.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    protected function flushed(\Closure $work): mixed
    {
        $result = $work();
        $this->em()->flush();

        return $result;
    }

    /**
     * A firm of the active organisation with a bank account, and a customer.
     *
     * @return array{string, string} the ids of the firm and the customer
     */
    protected function firmWithCustomer(string $firmName = 'Example Firm s.r.o.', string $registrationNumber = '12345695'): array
    {
        $em = $this->em();
        $firm = new LegalEntity($firmName, new RegistrationNumber('CZ', $registrationNumber), VatId::fromString('CZ'.$registrationNumber), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
        $em->persist($firm);
        FirmAccounts::add($em, self::IBAN);
        $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '+420 000 000 000');
        $em->persist($customer);
        $em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $em->persist(new PartyEmail($customer, 'billing@example.com', EmailPurpose::Invoices));
        $em->flush();

        return [$firm->id(), $customer->id()];
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
}
