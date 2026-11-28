<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\TaxesAndContributions\Infrastructure;

use Foresi\Identity\Domain\Role;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\TaxesAndContributions\Contract\Authority;
use Foresi\TaxesAndContributions\Contract\DuePayment;
use Foresi\TaxesAndContributions\Contract\EstimateView;
use Foresi\TaxesAndContributions\Infrastructure\Screen\TaxesScreen;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The page Money → Taxes and contributions: what the owner keeps to date
 * beside the year end, the methods compared, the credits, and the steps of
 * every number in the sheet; a member sees neither the page nor the item.
 * The years by the Czech rules are in Integration/Country.
 */
final class TaxesControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private SignedInBrowser $browser;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-20 10:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, self::getContainer()->get('doctrine.orm.entity_manager'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAPaidPaymentSaysWhenTheBankBookedItInCzech(): void
    {
        $this->signedIn(Role::Owner);
        $day = static fn (string $day): \DateTimeImmutable => new \DateTimeImmutable($day, new \DateTimeZone('Europe/Prague'));
        $czk = Money::ofMinor(500_500, Currency::of('CZK'));
        $paid = new DuePayment(Authority::Social, DuePayment::KIND_ADVANCE, '2026-10', $day('2026-10-01'), $day('2026-10-31'), $czk, DuePayment::STATE_PAID, '1/0100', '1', 'm1', $day('2026-10-30'));
        $open = new DuePayment(Authority::Health, DuePayment::KIND_ADVANCE, '2026-11', $day('2026-11-01'), $day('2026-12-08'), $czk);
        $estimate = new EstimateView(2026, $day('2026-11-20'), null, null, null, null, null, [], [], [], payments: [$paid, $open]);

        $rows = self::getContainer()->get(TaxesScreen::class)->payments($estimate);

        self::assertSame([DuePayment::STATE_PAID, 'Zaplaceno 30. 10. 2026', 'green'], [$rows[0]['state'], $rows[0]['stateLabel'], $rows[0]['tone']]);
        self::assertSame(['Očekává se', 'outline', 'Zdravotní pojištění – záloha 11/2026'], [$rows[1]['stateLabel'], $rows[1]['tone'], $rows[1]['label']]);
    }

    public function testAMemberIsRefused(): void
    {
        $this->signedIn(Role::Member);

        foreach (['/taxes-and-contributions', '/taxes-and-contributions?year=2027', '/taxes-and-contributions/steps/2026/actual/social'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseStatusCodeSame(403, $page);
        }
    }

    private function signedIn(Role $role): void
    {
        $this->browser->seed(role: $role);
        $this->browser->signIn();
        $em = $this->browser->em();
        $em->persist(new LegalEntity('Example Sole Trader', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, ''));
        $em->flush();
    }
}
