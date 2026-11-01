<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure;

use App\Identity\Domain\Role;
use App\Invoicing\Infrastructure\Controller\CustomerPickerController;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\SignedInBrowser;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(CustomerPickerController::class)]
final class CustomerPickerControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private TenantContext $context;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'), $this->context);
    }

    public function testAnswersWithTheOptionsAsJson(): void
    {
        [$tenant] = $this->browser->seed(['Example Company']);
        $firm = $this->firm($tenant);
        $this->browser->signIn();

        $this->client->request('GET', '/invoices/customers', ['firm' => $firm->id(), 'q' => 'Example Customer']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        /** @var array{results: list<array<string, mixed>>} $data */
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $data['results']);
        $option = $data['results'][0];
        self::assertSame('Example Customer a.s.', $option['name']);
        self::assertSame(14, $option['dueDays']);
        self::assertSame('bank_transfer', $option['paymentMethod']);
        self::assertSame('cs', $option['language']);
        self::assertFalse($option['reverseCharge']);
        self::assertSame('CZK', $option['currency']);
        self::assertSame(['street' => 'Customer Street 2', 'city' => 'Customer City', 'postalCode' => '200 00', 'countryCode' => 'CZ'], $option['billing']);
    }

    public function testAForeignFirmAnswers404(): void
    {
        [$mine] = $this->browser->seed(['Example Company']);
        $this->firm($mine);
        $foreign = $this->firm($this->browser->fixtures->tenant('Example Other'));
        $this->browser->signIn();

        $this->client->request('GET', '/invoices/customers', ['firm' => $foreign->id(), 'q' => 'Example']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAMissingFirmAnswers404(): void
    {
        $this->browser->seed(['Example Company']);
        $this->browser->signIn();

        $this->client->request('GET', '/invoices/customers', ['q' => 'Example']);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{Role, int}>
     */
    public static function roles(): iterable
    {
        yield 'owner' => [Role::Owner, 200];
        yield 'member' => [Role::Member, 200];
        yield 'subcontractor' => [Role::Subcontractor, 403];
        yield 'client' => [Role::Client, 403];
    }

    #[DataProvider('roles')]
    public function testOnlyStaffReachThePicker(Role $role, int $status): void
    {
        [$tenant] = $this->browser->seed(['Example Company'], role: $role);
        $firm = $this->firm($tenant);
        $this->browser->signIn();

        $this->client->request('GET', '/invoices/customers', ['firm' => $firm->id(), 'q' => 'Example']);

        self::assertResponseStatusCodeSame($status);
    }

    private function firm(TenantId $tenant): LegalEntity
    {
        $this->context->switchTo($tenant);
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, 'Register', []);
        $this->em()->persist($firm);
        $party = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), null, '');
        $this->em()->persist($party);
        $this->em()->persist(new PartyAddress($party, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
        $this->em()->flush();
        $this->context->reset();

        return $firm;
    }

    private function em(): EntityManagerInterface
    {
        return $this->browser->em();
    }
}
