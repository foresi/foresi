<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Integration\Invoicing;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\AddressType;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyAddress;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Party\Support\FirmAccounts;
use Foresi\Timesheet\Domain\ProjectTimeSetting;
use Foresi\Timesheet\Domain\TimeEntry;
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\WorkflowTemplate;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * "Invoice time": the header action of a project and of the report, for an
 * owner; the dialog with the firm, the client, its projects and last month,
 * the billable time not invoiced yet all ticked with its totals. Continue to
 * the invoice, which needs the invoicing rules of the firm's country, is in
 * Integration/Country.
 */
final class TimeInvoiceControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private EntityManagerInterface $em;
    private string $website;
    private string $shop;
    private string $homepage;
    private string $customer;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-10 12:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->browser = new SignedInBrowser($this->client, $this->em);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheDialogOffersLastMonthsBillableTimeOfTheProjectAllTicked(): void
    {
        $this->seed();
        $this->logged($this->homepage, 50, '2026-10-05', note: 'Wireframes');
        $this->logged(null, 20, '2026-10-06');
        $this->logged($this->homepage, 30, '2026-11-02');
        $this->logged($this->homepage, 40, '2026-10-07', billable: false);
        $this->logged(null, 15, '2026-10-08', project: 'Shop');

        $crawler = $this->client->request('GET', '/projects/'.$this->website);
        self::assertSame('/time/invoice?project='.$this->website, $crawler->filter('a[data-time-invoice-open]')->attr('href'));
        $crawler = $this->client->request('GET', '/time/report');
        self::assertSame('/time/invoice', $crawler->filter('a[data-time-invoice-open]')->attr('href'));

        $crawler = $this->dialog('/time/invoice?project='.$this->website);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[name="firm"]'), 'The instance firm invoices the time (ADR 0039).');
        self::assertSame($this->customer, $crawler->filter('#time-invoice-client option[selected]')->attr('value'));
        self::assertSame(['Shop', 'Website'], $crawler->filter('[data-time-invoice-projects] label')->each(static fn (Crawler $label): string => trim($label->text())));
        self::assertSame([$this->website], $crawler->filter('[data-time-invoice-projects] input:checked')->each(static fn (Crawler $box): string => (string) $box->attr('value')));
        self::assertSame(['2026-10-01', '2026-10-31'], [$crawler->filter('#time-invoice-from')->attr('value'), $crawler->filter('#time-invoice-until')->attr('value')]);
        // Billable, finished and of last month: not November's, not the unbillable, not Shop's.
        $entries = $crawler->filter('[data-time-invoice-entry]');
        self::assertCount(2, $entries);
        self::assertCount(2, $entries->filter('input[name="entries[]"]:checked'));
        self::assertSame('task', $crawler->filter('#time-invoice-grouping option[selected]')->attr('value'));
        self::assertSame('none', $crawler->filter('#time-invoice-rounding option[selected]')->attr('value'));
        // A line per task: 20 minutes are 0.33 h, 50 minutes 0.83 h.
        self::assertSame('1.16 h', trim($crawler->filter('[data-time-invoice-hours]')->text()));
        self::assertSame('CZK 1,160.00', $this->plain($crawler->filter('[data-time-invoice-amount]')->text()));

        // Another client's projects come all ticked; the projects ticked stay while the client does.
        $form = $crawler->filter('#time-invoice-form')->form();
        $this->tick($form, 'projects', [$this->website, $this->shop]);
        $crawler = $this->client->submit($form);
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('[data-time-invoice-entry]'));
    }

    public function testOnlyAnOwnerInvoicesTime(): void
    {
        $this->seed(Role::Member);

        $this->client->request('GET', '/time/invoice');
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', '/time/report');
        self::assertCount(0, $crawler->filter('a[data-time-invoice-open]'));
    }

    private function seed(Role $role = Role::Owner): void
    {
        $this->browser->seed(role: $role);
        $this->scoped(function (): void {
            $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
            $this->em->persist($firm);
            FirmAccounts::add($this->em, self::IBAN);
            $series = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
            $series->markDefault();
            $this->em->persist($series);
            $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
            $this->em->persist($customer);
            $this->em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
            $owner = $this->membership();
            $now = new \DateTimeImmutable('2026-09-01');
            $website = new Project('Website', $customer, WorkflowTemplate::Simple, $owner->user()->id(), $now);
            $shop = new Project('Shop', $customer, WorkflowTemplate::Simple, $owner->user()->id(), $now);
            $this->em->persist($website);
            $rate = new ProjectTimeSetting($website);
            $rate->changeHourlyRate(Money::of('1000', Currency::CZK));
            $this->em->persist($rate);
            $this->em->persist($shop);
            $open = new ProjectStatus($website, 'Open', \Foresi\Work\Domain\StatusType::Open, 0);
            $this->em->persist($open);
            $homepage = new Task($website, 'Design the homepage', $open, 0, $owner->user()->id(), $now);
            $this->em->persist($homepage);
            $this->em->flush();
            [$this->customer, $this->website, $this->shop, $this->homepage] = [$customer->id(), $website->id(), $shop->id(), $homepage->id()];
        });
        $this->browser->signIn();
    }

    private function logged(?string $task, int $minutes, string $day, bool $billable = true, string $note = '', string $project = 'Website'): string
    {
        return $this->scoped(function () use ($task, $minutes, $day, $billable, $note, $project): string {
            $onTask = null === $task ? null : $this->em->find(Task::class, $task);
            $person = $this->membership();
            $onProject = $onTask?->project() ?? $this->em->getRepository(Project::class)->findOneBy(['name' => $project]) ?? throw new \LogicException('Project.');
            $entry = TimeEntry::logged($onProject, $onTask, $person, new \DateTimeImmutable($day), $minutes, null, null, $note, $billable, $person->user()->id(), new \DateTimeImmutable($day.' 18:00'));
            $this->em->persist($entry);
            $this->em->flush();

            return $entry->id();
        });
    }

    /**
     * Ticks the boxes of the values and unticks the others.
     *
     * @param list<string> $values
     */
    private function tick(Form $form, string $name, array $values): void
    {
        $boxes = $form[$name];
        foreach (\is_array($boxes) ? $boxes : [$boxes] as $box) {
            self::assertInstanceOf(ChoiceFormField::class, $box);
            \in_array($box->availableOptionValues()[0], $values, true) ? $box->tick() : $box->untick();
        }
    }

    private function dialog(string $url): Crawler
    {
        return $this->client->request('GET', $url, server: ['HTTP_'.str_replace('-', '_', strtoupper(DialogRequest::HEADER)) => '1']);
    }

    private function membership(): Membership
    {
        $user = $this->em->getConnection()->fetchOne('SELECT id FROM app_user WHERE email = ?', [IdentityFixtures::EMAIL]);

        return $this->em->getRepository(Membership::class)->findOneBy(['user' => $user]) ?? throw new \LogicException('Membership.');
    }

    private function plain(string $text): string
    {
        return trim(str_replace(["\u{a0}", "\u{202f}"], ' ', $text));
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
}
