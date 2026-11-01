<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Application;

use App\Party\Application\LegalEntities;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tests\Identity\Support\SignedInBrowser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ActiveLegalEntitiesTest extends WebTestCase
{
    private TenantContext $context;

    protected function setUp(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->context = self::getContainer()->get(TenantContext::class);
        $browser = new SignedInBrowser($client, $this->em(), $this->context);
        [$this->tenantA, $this->tenantB] = $browser->seed(['Tenant A', 'Tenant B']);
    }

    private \App\Tenancy\Domain\TenantId $tenantA;
    private \App\Tenancy\Domain\TenantId $tenantB;

    public function testActiveLeavesArchivedFirmsOutAndSortsByName(): void
    {
        $this->context->switchTo($this->tenantA);
        $this->add('Zeta Firm', '12345695');
        $this->add('Alpha Firm', '12345679');
        $old = $this->add('Old Firm', '25596641');
        $this->transaction()->run(static fn () => $old->archive(new \DateTimeImmutable('2026-10-31 12:00:00')));

        $active = $this->firms()->active();

        self::assertSame(['Alpha Firm', 'Zeta Firm'], array_map(static fn (LegalEntity $firm): string => $firm->name(), $active));
        self::assertCount(3, $this->firms()->all());
    }

    public function testActiveIsScopedToTheTenant(): void
    {
        $this->context->switchTo($this->tenantA);
        $this->add('Tenant A Firm', '12345695');
        $this->context->switchTo($this->tenantB);
        $this->add('Tenant B Firm', '12345679');

        self::assertSame(['Tenant B Firm'], array_map(static fn (LegalEntity $firm): string => $firm->name(), $this->firms()->active()));

        $this->context->switchTo($this->tenantA);

        self::assertSame(['Tenant A Firm'], array_map(static fn (LegalEntity $firm): string => $firm->name(), $this->firms()->active()));
    }

    public function testNoFirmsGiveAnEmptyList(): void
    {
        $this->context->switchTo($this->tenantA);

        self::assertSame([], $this->firms()->active());
    }

    private function add(string $name, string $number): LegalEntity
    {
        $firm = new LegalEntity($name, new RegistrationNumber('CZ', $number), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '', []);
        $this->transaction()->run(fn () => $this->firms()->add($firm));

        return $firm;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }

    private function firms(): LegalEntities
    {
        return self::getContainer()->get(LegalEntities::class);
    }

    private function transaction(): TransactionalWork
    {
        return self::getContainer()->get(TransactionalWork::class);
    }
}
