<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Margin\Integration\Purchasing;

use Foresi\Identity\Domain\Role;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\CostShareSource;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Instance\Support\InstanceZone;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * The sheet over a cell of the Margin page offers to assign each unassigned received
 * document, through the assignment dialog of the Purchasing integration. Today is
 * 10 December 2026.
 */
final class MarginSheetAssignTest extends WebTestCase
{
    private const string UNCLAIMED_ONE = '00000000-0000-7000-8000-0000000000d1';
    private const string UNCLAIMED_TWO = '00000000-0000-7000-8000-0000000000d2';
    private const string CLIENT_COST = '00000000-0000-7000-8000-0000000000d3';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private Party $clientA;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-12-10 09:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        InstanceZone::set(self::getContainer());
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheSheetOffersToAssignTheUnclaimedDocumentsOnly(): void
    {
        $this->start();
        $this->share(self::CLIENT_COST, 'client', 72_996, CostShareSource::Line);
        $this->share(self::UNCLAIMED_ONE, null, 10_000, CostShareSource::Unassigned);
        $this->share(self::UNCLAIMED_TWO, null, 20_000, CostShareSource::Unassigned);

        $sheet = $this->client->request('GET', '/margin/cell', ['row' => 'unassigned', 'month' => '2026-11'], server: ['HTTP_X_FORESI_DIALOG' => 'sheet']);
        self::assertResponseIsSuccessful();
        self::assertSame(['/margin/documents/'.self::UNCLAIMED_TWO.'/assign', '/margin/documents/'.self::UNCLAIMED_ONE.'/assign'], $sheet->filter('a[data-margin-assign][data-dialog]')->each(static fn (Crawler $link): string => (string) $link->attr('href')));

        $client = $this->client->request('GET', '/margin/cell', ['row' => 'client:'.$this->clientA->id(), 'month' => '2026-11'], server: ['HTTP_X_FORESI_DIALOG' => 'sheet']);
        self::assertCount(1, $client->filter('[data-margin-item]'));
        self::assertCount(0, $client->filter('a[data-margin-assign]'), 'Only an unassigned document is offered for assignment.');
    }

    private function share(string $document, ?string $recipient, int $minor, CostShareSource $source): void
    {
        $em = $this->browser->em();
        $em->persist(new CostShare(Uuid::v7()->toRfc4122(), $document, 'rate:21', new \DateTimeImmutable('2026-11-01'), 'client' === $recipient ? $this->clientA->id() : $recipient, $minor, $source));
        $em->flush();
    }

    private function start(): void
    {
        $this->browser->seed('en', Role::Owner);
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $this->clientA = new Party(PartyKind::Company, 'Client A s.r.o.', new RegistrationNumber('CZ', '27082440'), null, '');
        $em->persist($this->clientA);
        $em->flush();
        $this->browser->signIn();
    }
}
