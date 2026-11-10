<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Timesheet\Infrastructure;

use App\Identity\Domain\Membership;
use App\Identity\Domain\Role;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Party\Domain\Address;
use App\Party\Domain\AddressType;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\Party;
use App\Party\Domain\PartyAddress;
use App\Party\Domain\PartyKind;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatId;
use App\Party\Domain\VatStatus;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use App\Tests\Identity\Support\IdentityFixtures;
use App\Tests\Identity\Support\SignedInBrowser;
use App\Tests\Party\Support\FirmAccounts;
use App\Ui\Dialog\DialogRequest;
use App\Work\Domain\Project;
use App\Work\Domain\ProjectStatus;
use App\Work\Domain\Task;
use App\Work\Domain\TimeEntry;
use App\Work\Domain\WorkflowTemplate;
use Doctrine\ORM\EntityManagerInterface;
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
 * the billable time not invoiced yet all ticked with its totals; Continue to
 * the new invoice form with the lines; and saving it locks the time, which
 * then names its invoice instead of its actions.
 */
final class TimeInvoiceControllerTest extends WebTestCase
{
    private const string IBAN = 'CZ6508000000192000145399';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private EntityManagerInterface $em;
    private TenantContext $context;
    private TenantId $tenant;
    private string $website;
    private string $shop;
    private string $homepage;
    private string $customer;
    private string $firm;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-10 12:00:00', 'UTC'));
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $this->context = self::getContainer()->get(TenantContext::class);
        $this->browser = new SignedInBrowser($this->client, $this->em, $this->context);
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
        self::assertSame($this->firm, $crawler->filter('#time-invoice-firm option[selected]')->attr('value'));
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

    public function testContinueOpensTheNewInvoiceFormWithTheLinesAndSavingLocksTheTime(): void
    {
        $this->seed();
        $design = $this->logged($this->homepage, 50, '2026-10-05');
        $more = $this->logged($this->homepage, 20, '2026-10-06');
        $planning = $this->logged(null, 10, '2026-10-07');
        $later = $this->logged(null, 5, '2026-10-09');

        $crawler = $this->dialog('/time/invoice?project='.$this->website);
        $form = $crawler->filter('#time-invoice-form')->form(['rounding' => '15']);
        $this->tick($form, 'entries', [$design, $more, $planning]);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues() + ['continue' => '1']);
        self::assertResponseStatusCodeSame(303);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/invoices/new?billedWork=', $location);

        $crawler = $this->client->request('GET', $location);
        self::assertResponseIsSuccessful();
        /** @var array{firmId: string, customerId: string, billedWork: string, lineSources: string, lines: list<array<string, string>>} $values */
        $values = $crawler->filter('form#invoice-form')->form()->getPhpValues();
        self::assertSame($this->firm, $values['firmId']);
        self::assertSame($this->customer, $values['customerId']);
        self::assertNotSame('', $values['billedWork']);
        self::assertSame([
            ['Website', '0.25', 'h', '1000.00', 'base'],
            ['Website – Design the homepage', '1.5', 'h', '1000.00', 'base'],
        ], array_map(static fn (array $line): array => [$line['text'], $line['quantity'], $line['unit'], $line['price'], $line['vat']], $values['lines']));
        self::assertSame(['line-1', 'line-2'], json_decode($values['lineSources'], true));

        // The project's line is removed and the other edited: its time is billed all the same.
        $values['lines'] = [array_merge($values['lines'][1], ['text' => 'Homepage', 'price' => '900'])];
        $values['lineSources'] = '["line-2"]';
        $this->client->request('POST', '/invoices/new', array_merge($values, ['_action' => 'save']));
        self::assertResponseRedirects('/invoices');

        $invoice = $this->row('SELECT id, number FROM invoice');
        [$invoiceId, $number] = [(string) $invoice['id'], (string) $invoice['number']];
        $line = $this->row('SELECT id FROM invoice_line WHERE invoice_id = ?', [$invoiceId]);
        foreach ([$design => 60, $more => 30] as $entry => $minutes) {
            self::assertSame(['invoice_id' => $invoiceId, 'invoice_line_id' => $line['id'], 'invoiced_rate_minor' => '100000', 'invoiced_rate_currency' => 'CZK', 'invoiced_minutes' => (string) $minutes], $this->row('SELECT invoice_id, invoice_line_id, invoiced_rate_minor, invoiced_rate_currency, invoiced_minutes FROM time_entry WHERE id = ?', [$entry]));
        }
        foreach ([$planning, $later] as $entry) {
            self::assertSame(['invoice_id' => null], $this->row('SELECT invoice_id FROM time_entry WHERE id = ?', [$entry]));
        }

        // Invoiced time names its invoice instead of its actions, on the task and in the week.
        foreach (['/tasks/'.$this->homepage, '/time?week=2026-10-05'] as $page) {
            $crawler = $this->client->request('GET', $page);
            $line = $crawler->filter('#time-'.$design);
            self::assertSame('Invoice '.$number, trim($line->filter('a[data-time-invoice]')->text()), $page);
            self::assertSame('/invoices/'.$invoiceId.'/edit', $line->filter('a[data-time-invoice]')->attr('href'));
            self::assertCount(0, $line->filter('[data-time-edit], [data-time-delete]'), $page);
        }
        $crawler = $this->dialog('/time/invoice?project='.$this->website);
        self::assertSame([$planning, $later], $crawler->filter('input[name="entries[]"]')->each(static fn (Crawler $box): string => (string) $box->attr('value')));
    }

    public function testTimeWithoutARateIsRefusedUnlessUnticked(): void
    {
        $this->seed();
        $priced = $this->logged($this->homepage, 60, '2026-10-05');
        $this->logged(null, 30, '2026-10-06', project: 'Shop');

        $crawler = $this->dialog('/time/invoice?project='.$this->shop);
        self::assertCount(1, $crawler->filter('[data-time-invoice-no-rate]'));
        self::assertSame('—', trim($crawler->filter('[data-time-invoice-amount]')->text()));
        $form = $crawler->filter('#time-invoice-form')->form();
        $this->tick($form, 'projects', [$this->website, $this->shop]);
        $crawler = $this->client->submit($form);
        $form = $crawler->filter('#time-invoice-form')->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues() + ['continue' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Ticked time has no hourly rate', $this->client->getCrawler()->filter('[data-time-invoice-error]')->text());

        $form = $this->client->getCrawler()->filter('#time-invoice-form')->form();
        $this->tick($form, 'entries', [$priced]);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues() + ['continue' => '1']);
        self::assertResponseStatusCodeSame(303);
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
        [$this->tenant] = $this->browser->seed(['Example Organisation'], role: $role);
        $this->scoped(function (): void {
            $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::Registered, 'Example register, entry C 1');
            $this->em->persist($firm);
            FirmAccounts::add($this->em, $firm, self::IBAN);
            $series = new Series($firm, Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
            $series->markDefault();
            $this->em->persist($series);
            $customer = new Party(PartyKind::Company, 'Example Customer a.s.', new RegistrationNumber('CZ', '87654320'), VatId::fromString('CZ87654320'), '');
            $this->em->persist($customer);
            $this->em->persist(new PartyAddress($customer, AddressType::Billing, new Address('Customer Street 2', 'Customer City', '200 00', 'CZ')));
            $owner = $this->membership();
            $now = new \DateTimeImmutable('2026-09-01');
            $website = new Project('Website', $customer, WorkflowTemplate::Simple, $owner->user()->id(), $now);
            $website->changeHourlyRate(Money::of('1000', Currency::CZK));
            $shop = new Project('Shop', $customer, WorkflowTemplate::Simple, $owner->user()->id(), $now);
            $this->em->persist($website);
            $this->em->persist($shop);
            $open = new ProjectStatus($website, 'Open', \App\Work\Domain\StatusType::Open, 0);
            $this->em->persist($open);
            $homepage = new Task($website, 'Design the homepage', $open, 0, $owner->user()->id(), $now);
            $this->em->persist($homepage);
            $this->em->flush();
            [$this->firm, $this->customer, $this->website, $this->shop, $this->homepage] = [$firm->id(), $customer->id(), $website->id(), $shop->id(), $homepage->id()];
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

    /**
     * The row with its values as text, whatever the database returns them as.
     *
     * @param list<string> $parameters
     *
     * @return array<string, ?string>
     */
    private function row(string $sql, array $parameters = []): array
    {
        $row = $this->em->getConnection()->fetchAssociative($sql, $parameters);
        self::assertIsArray($row);

        return array_map(static fn (mixed $value): ?string => \is_scalar($value) ? (string) $value : null, $row);
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
        $this->context->switchTo($this->tenant);
        try {
            return $work();
        } finally {
            $this->context->reset();
        }
    }
}
