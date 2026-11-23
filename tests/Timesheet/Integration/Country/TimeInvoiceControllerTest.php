<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Timesheet\Integration\Country;

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
use Foresi\Ui\Contract\Dialog\DialogRequest;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TimeEntry;
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
 * "Invoice time" by the invoicing rules of the firm's country: Continue to the
 * new invoice form with the lines, and saving it locks the time, which then
 * names its invoice instead of its actions; time without a rate is refused
 * unless unticked.
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
        /** @var array{customerId: string, billedWork: string, lineSources: string, lines: list<array<string, string>>} $values */
        $values = $crawler->filter('form#invoice-form')->form()->getPhpValues();
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
        self::assertSame('Not set', $crawler->filter('[data-empty-value][data-time-invoice-target=amountEmpty]')->text());
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
            $website->changeHourlyRate(Money::of('1000', Currency::CZK));
            $shop = new Project('Shop', $customer, WorkflowTemplate::Simple, $owner->user()->id(), $now);
            $this->em->persist($website);
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
