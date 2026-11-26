<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Perf;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Activities\Application\ActivityWrites;
use Foresi\Activities\Application\Api\CreateActivityInput;
use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Deals\Application\Api\CreateDealInput;
use Foresi\Deals\Application\DealWrites;
use Foresi\Deals\Contract\SalesRecords;
use Foresi\Deals\Domain\Stage;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Invoicing\Application\AllocationRequest;
use Foresi\Invoicing\Application\InvoiceInput;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\LineInput;
use Foresi\Invoicing\Application\PaymentRequest;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Contract\Percent;
use Foresi\Invoicing\Contract\PriceType;
use Foresi\Invoicing\Contract\Quantity;
use Foresi\Invoicing\Contract\TaxTreatment;
use Foresi\Invoicing\Contract\VatRateKind;
use Foresi\Invoicing\Domain\PaymentSource;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Application\CreateParty;
use Foresi\Party\Application\PartyInput;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\DocumentLanguage;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Party\Domain\Iban;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\PaymentMethod;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\ReceivedDocuments\Application\ReceivedDocumentInput;
use Foresi\ReceivedDocuments\Application\RecordReceivedDocument;
use Foresi\ReceivedDocuments\Domain\PurchaseTaxTreatment;
use Foresi\ReceivedDocuments\Domain\ReceivedDocumentKind;
use Foresi\ReceivedDocuments\Domain\VatRate;
use Foresi\ReceivedDocuments\Domain\VatRecapLine;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Id;
use Foresi\Shared\Domain\Money;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Application\ManageTime;
use Foresi\Work\Domain\Project;
use Foresi\Work\Domain\Task;
use Foresi\Work\Domain\TimeEntry;
use Foresi\Work\Domain\WorkflowTemplate;
use Psr\Container\ContainerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The data of the performance baseline (plan step 36.12): one firm with its owner
 * and the records of a PerfSize, written through the application services, so
 * every row is one the application itself would write. Only the time entries
 * after the first are written with DBAL (see timeEntries()). The random choices come
 * from a fixed seed, so every run writes the same names, amounts and day offsets;
 * the days count back from the day of the run.
 *
 * It expects an empty schema: a migrated PostgreSQL database, or the SQLite schema
 * a test builds with the SchemaTool (which lacks the stages and activity types the
 * migrations seed, so they are added here when missing).
 */
final class PerfData
{
    public const string OWNER_EMAIL = 'perf@example.com';
    public const string DEFAULT_PASSWORD = 'perf-password-1';
    public const int SEED = 3612;

    // The entity manager is cleared and the in-memory queues emptied after this many
    // records, so memory stays flat however many rows are written.
    private const int BATCH = 200;
    private const string FIRM_IBAN = 'CZ6508000000192000145399';
    // The synthetic registration numbers the other tests use; contacts share them, as nothing
    // keeps two contacts from having one number.
    private const array REGISTRATION_NUMBERS = ['12345679', '00000019', '00000001', '12345687'];
    // Rows per INSERT of the time entries written with DBAL.
    private const int INSERT_ROWS = 500;
    private const array TRANSPORTS = ['async', 'registry', 'mail', 'events', 'webhooks', 'failed'];
    // The invoices spread over the last five years; time over the last year.
    private const int INVOICE_DAYS = 5 * 365;
    private const int TIME_DAYS = 365;

    private readonly Randomizer $random;
    private readonly EntityManagerInterface $em;
    private readonly \DateTimeImmutable $today;
    private string $ownerId = '';
    /** @var array<string, float> */
    private array $seconds = [];
    /** @var list<string> */
    private array $customers = [];
    /** @var list<string> */
    private array $suppliers = [];

    /**
     * @param \Closure(string, int, int): void|null $progress
     */
    private function __construct(
        private readonly ContainerInterface $container,
        private readonly PerfSize $size,
        private readonly ?\Closure $progress,
    ) {
        mt_srand(self::SEED);
        $this->random = new Randomizer(new Mt19937(self::SEED));
        $this->em = $this->service(EntityManagerInterface::class);
        $this->today = $this->service(InstanceClock::class)->today();
    }

    /**
     * @param ContainerInterface                    $container    the test service container, where the private services are found by class
     * @param string|null                           $passwordHash the owner's hash; null for a hash nobody signs in with
     * @param \Closure(string, int, int): void|null $progress     told the record type, how many are done and how many there will be
     */
    public static function seed(ContainerInterface $container, PerfSize $size, ?\Closure $progress = null, ?string $passwordHash = null): PerfWorld
    {
        return new self($container, $size, $progress)->run($passwordHash ?? password_hash(self::DEFAULT_PASSWORD, \PASSWORD_BCRYPT, ['cost' => 4]));
    }

    private function run(string $passwordHash): PerfWorld
    {
        [$ownerId, $tokens] = $this->timed('setup', fn (): array => $this->setUp($passwordHash));
        $tokens->setToken(new UsernamePasswordToken(new SecurityUser($ownerId, self::OWNER_EMAIL, 'hash'), 'main', ['ROLE_USER']));
        try {
            $contact = $this->timed('contacts', $this->contacts(...));
            [$invoice, $payable] = $this->timed('invoices', $this->invoices(...));
            $this->timed('payments', fn () => $this->payments($payable));
            unset($payable);
            $document = $this->timed('received_documents', $this->receivedDocuments(...));
            $projects = $this->timed('projects', $this->projects(...));
            $tasks = $this->timed('tasks', fn (): array => $this->tasks($projects));
            $this->timed('time_entries', fn () => $this->timeEntries($tasks));
            $deals = $this->timed('deals', $this->deals(...));
            $this->timed('activities', fn () => $this->activities($deals));
        } finally {
            $tokens->setToken(null);
        }

        return new PerfWorld(
            $this->size,
            $ownerId,
            self::OWNER_EMAIL,
            $contact,
            $invoice,
            $projects[0] ?? null,
            $tasks[0][1] ?? null,
            $deals[0] ?? null,
            $document,
            $this->size->counts(),
            $this->seconds,
        );
    }

    /**
     * The owner, the firm with its bank account and invoice series, and what the
     * migrations seed when the schema came from the SchemaTool.
     *
     * @return array{string, TokenStorageInterface}
     */
    private function setUp(string $passwordHash): array
    {
        if (null !== $this->em->getRepository(User::class)->findOneBy([])) {
            throw new \LogicException('The performance seed writes into an empty database only.');
        }
        $owner = new User(self::OWNER_EMAIL, $passwordHash, 'Perf Example', 'cs');
        $this->em->persist($owner);
        $this->em->persist(new Membership($owner, Role::Owner));
        $this->em->persist(new LegalEntity('Example Perf s.r.o.', new RegistrationNumber('CZ', '12345695'), VatId::fromString('CZ12345695'), $this->address(0), VatStatus::Registered, 'Example register, entry C 3612'));
        $account = FirmBankAccount::open(Iban::fromString(self::FIRM_IBAN), Currency::CZK, '', 0);
        $account->markPrimary(true);
        $this->em->persist($account);
        $series = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
        $series->markDefault();
        $this->em->persist($series);
        $now = new \DateTimeImmutable();
        if (null === $this->em->getRepository(Stage::class)->findOneBy([])) {
            foreach (['inquiry', 'meeting', 'proposal', 'negotiation'] as $position => $key) {
                $this->em->persist(new Stage($key, null, $position, $now));
            }
        }
        if (null === $this->em->getRepository(ActivityType::class)->findOneBy([])) {
            foreach ([['meeting', ActivityTiming::Timed, 60], ['call', ActivityTiming::Timed, 15], ['note', ActivityTiming::None, null], ['task', ActivityTiming::Date, null]] as [$key, $timing, $duration]) {
                $this->em->persist(new ActivityType($key, null, $timing, $duration, $now));
            }
        }
        $this->em->flush();
        $this->ownerId = $owner->id();
        $this->em->clear();

        return [$this->ownerId, $this->service(TokenStorageInterface::class)];
    }

    /**
     * Two in three contacts are customers, the rest suppliers.
     */
    private function contacts(): ?string
    {
        $create = $this->service(CreateParty::class);
        $first = null;
        for ($i = 1; $i <= $this->size->contacts; ++$i) {
            $supplier = 0 === $i % 3;
            $kind = 0 === $i % 5 ? PartyKind::SoleTrader : PartyKind::Company;
            $name = PartyKind::Company === $kind ? \sprintf('Example Company %04d s.r.o.', $i) : \sprintf('Example Trader %04d', $i);
            $party = $create->createFrom(new PartyInput(
                $kind,
                $name,
                PartyKind::Company === $kind ? new RegistrationNumber('CZ', self::REGISTRATION_NUMBERS[$i % \count(self::REGISTRATION_NUMBERS)]) : null,
                null,
                \sprintf('contact%04d@example.com', $i),
                \sprintf('+420 000 %03d %03d', intdiv($i, 1000) % 1000, $i % 1000),
                [$supplier ? PartyRole::Supplier : PartyRole::Customer],
                $this->address($i),
            ));
            $id = $party->id();
            $first ??= $id;
            if ($supplier) {
                $this->suppliers[] = $id;
            } else {
                $this->customers[] = $id;
            }
            $this->step('contacts', $i, $this->size->contacts);
        }
        // A size with fewer than three contacts still has a supplier to receive from.
        if ([] === $this->suppliers && [] !== $this->customers) {
            $this->suppliers = $this->customers;
        }
        if ([] === $this->customers) {
            $this->customers = $this->suppliers;
        }

        return $first;
    }

    /**
     * Issued in the order of their days, so each year's numbers run in the order of
     * the calendar; nothing refuses an issue day in the past while no VAT period
     * is settled, and an empty database has none.
     *
     * @return array{?string, list<array{string, Money, \DateTimeImmutable}>} the first invoice, and the id, total and day of those to pay
     */
    private function invoices(): array
    {
        $issue = $this->service(IssueInvoice::class);
        $count = $this->size->invoices;
        $first = null;
        $payable = [];
        for ($i = 0; $i < $count; ++$i) {
            $day = $this->today->modify(\sprintf('-%d days', self::INVOICE_DAYS - intdiv($i * self::INVOICE_DAYS, max(1, $count))));
            $lines = [];
            for ($line = 1, $lineCount = $this->random->getInt(1, 10); $line <= $lineCount; ++$line) {
                $lines[] = new LineInput(
                    \sprintf('Example service %d', $this->random->getInt(1, 50)),
                    Quantity::fromString((string) $this->random->getInt(1, 20)),
                    'h',
                    Money::ofMinor($this->random->getInt(100, 5_000) * 100, Currency::CZK),
                    PriceType::WithoutVat,
                    TaxTreatment::Standard,
                    VatRateKind::Base,
                    null,
                    Percent::fromString('0'),
                );
            }
            $invoice = $issue->issue(new InvoiceInput(
                null, null, $this->pick($this->customers), null, $day, $day, $day->modify('+14 days'),
                PaymentMethod::BankTransfer, self::FIRM_IBAN, '', '', '', \sprintf('Example work %05d', $i + 1), '', '', DocumentLanguage::Czech, $lines,
                variableSymbolFollowsNumber: true,
            ));
            $first ??= $invoice->id();
            // Spreads the payments evenly over the invoices: exactly the size's count is paid.
            if (intdiv(($i + 1) * $this->size->payments, max(1, $count)) > intdiv($i * $this->size->payments, max(1, $count))) {
                $payable[] = [$invoice->id(), $invoice->payable(), $day];
            }
            $this->step('invoices', $i + 1, $count);
        }

        return [$first, $payable];
    }

    /**
     * Each pays its invoice in full, up to a month after its issue, never after today.
     *
     * @param list<array{string, Money, \DateTimeImmutable}> $payable
     */
    private function payments(array $payable): void
    {
        $record = $this->service(RecordPayment::class);
        foreach ($payable as $at => [$invoiceId, $amount, $day]) {
            $received = $day->modify(\sprintf('+%d days', $this->random->getInt(0, 30)));
            $received = $received > $this->today ? $this->today : $received;
            $record->record(new PaymentRequest($received, $amount, PaymentMethod::BankTransfer, PaymentSource::Manual, null, '', [new AllocationRequest($invoiceId, $amount)]));
            $this->step('payments', $at + 1, \count($payable));
        }
    }

    /**
     * Domestic tax invoices of the suppliers at the base rate, over the same five years.
     */
    private function receivedDocuments(): ?string
    {
        $record = $this->service(RecordReceivedDocument::class);
        $count = $this->size->receivedDocuments;
        $first = null;
        for ($i = 0; $i < $count; ++$i) {
            $day = $this->today->modify(\sprintf('-%d days', self::INVOICE_DAYS - intdiv($i * self::INVOICE_DAYS, max(1, $count))));
            $base = $this->random->getInt(500, 200_000) * 100;
            $vat = intdiv($base * 21 + 50, 100);
            $received = $day->modify('+1 day') > $this->today ? $this->today : $day->modify('+1 day');
            $saved = $record->create(new ReceivedDocumentInput(
                ReceivedDocumentKind::TaxInvoice,
                PurchaseTaxTreatment::Domestic,
                $this->pick($this->suppliers),
                \sprintf('EX-%06d', $i + 1),
                \sprintf('%010d', $i + 1),
                $day,
                $day,
                $day->modify('+14 days'),
                $received,
                Currency::CZK,
                Money::ofMinor($base + $vat, Currency::CZK),
                null,
                null,
                [new VatRecapLine(VatRate::of('21'), Money::ofMinor($base, Currency::CZK), Money::ofMinor($vat, Currency::CZK))],
            ));
            $first ??= $saved->document->id();
            $this->step('received_documents', $i + 1, $count);
        }

        return $first;
    }

    /**
     * Four in five for a client, the rest the firm's own.
     *
     * @return list<string>
     */
    private function projects(): array
    {
        $manage = $this->service(ManageProjects::class);
        $ids = [];
        for ($i = 1; $i <= $this->size->projects; ++$i) {
            $client = 0 === $i % 5 ? null : $this->pick($this->customers);
            $ids[] = $manage->create(\sprintf('Example Project %04d', $i), $client, 0 === $i % 2 ? WorkflowTemplate::Development : WorkflowTemplate::Simple, 'An example project of the performance baseline.')->id();
            $this->step('projects', $i, $this->size->projects);
        }

        return $ids;
    }

    /**
     * Spread evenly over the projects.
     *
     * @param list<string> $projects
     *
     * @return list<array{string, string}> the project and the task
     */
    private function tasks(array $projects): array
    {
        $manage = $this->service(ManageTasks::class);
        $ids = [];
        for ($i = 0; $i < $this->size->tasks; ++$i) {
            $project = $projects[$i % \count($projects)];
            $due = 0 === $i % 3 ? $this->today->modify(\sprintf('%+d days', $this->random->getInt(-60, 60))) : null;
            $ids[] = [$project, $manage->create($project, \sprintf('Example task %05d', $i + 1), dueOn: $due)->id()];
            $this->step('tasks', $i + 1, $this->size->tasks);
        }

        return $ids;
    }

    /**
     * The owner's time, spread evenly over the tasks and over the last year. The first
     * entry goes through ManageTime; the rest are written with DBAL, as ManageTime
     * writes them: the time_entry row and its "created" audit entry, one correlation
     * each. Through the service a time entry takes about 9 ms (one transaction and
     * flush each), which is a quarter of an hour for the large size.
     *
     * @param list<array{string, string}> $tasks
     */
    private function timeEntries(array $tasks): void
    {
        $count = $this->size->timeEntries;
        if (0 === $count) {
            return;
        }
        [$project, $task] = $tasks[0];
        $this->service(ManageTime::class)->log($project, $task, $this->today->modify(\sprintf('-%d days', $this->random->getInt(0, self::TIME_DAYS))), $this->random->getInt(1, 32) * 15, note: 'Example work log 000001');
        $this->step('time_entries', 1, $count);
        $connection = $this->em->getConnection();
        $membership = $this->em->getRepository(Membership::class)->findOneBy(['user' => $this->ownerId]) ?? throw new \LogicException('The owner has no membership.');
        $membershipId = $membership->id();
        $billable = [];
        foreach ($this->em->getRepository(Project::class)->findAll() as $one) {
            $billable[$one->id()] = $one->billsTimeByDefault();
        }
        $this->em->clear();
        $entries = [];
        $audits = [];
        for ($i = 1; $i < $count; ++$i) {
            [$project, $task] = $tasks[$i % \count($tasks)];
            $day = $this->today->modify(\sprintf('-%d days', $this->random->getInt(0, self::TIME_DAYS)))->setTime(0, 0);
            $minutes = $this->random->getInt(1, 32) * 15;
            $note = \sprintf('Example work log %06d', $i + 1);
            $now = new \DateTimeImmutable();
            $id = Id::generate();
            $entries[] = [$id, $project, $task, $membershipId, $day->format('Y-m-d'), $minutes, $note, $billable[$project], $this->ownerId, $now->format('Y-m-d H:i:s')];
            $audits[] = [Id::generate(), TimeEntry::class, $id, 'created', json_encode([
                'workedOn' => [null, $day->format('Y-m-d\\TH:i:s.uP')],
                'minutes' => [null, $minutes],
                'note' => [null, $note],
                'billable' => [null, $billable[$project]],
                'createdBy' => [null, $this->ownerId],
                'createdAt' => [null, $now->format('Y-m-d\\TH:i:s.uP')],
                'task' => [null, $task],
                'project' => [null, $project],
                'membership' => [null, $membershipId],
            ], \JSON_THROW_ON_ERROR), $now->format('Y-m-d H:i:s'), 'work:'.Id::generate(), $this->ownerId, Task::class, $task, 'user'];
            if (\count($entries) >= self::INSERT_ROWS || $i === $count - 1) {
                $connection->transactional(static function (Connection $connection) use ($entries, $audits): void {
                    self::insert($connection, 'time_entry', ['id', 'project_id', 'task_id', 'membership_id', 'worked_on', 'minutes', 'note', 'billable', 'created_by', 'created_at'], $entries, [7 => ParameterType::BOOLEAN, 5 => ParameterType::INTEGER]);
                    self::insert($connection, 'audit_entry', ['id', 'entity_class', 'entity_id', 'action', 'changes', 'occurred_at', 'correlation_id', 'user_id', 'root_class', 'root_id', 'actor_kind'], $audits, []);
                });
                $entries = [];
                $audits = [];
            }
            $this->step('time_entries', $i + 1, $count);
        }
    }

    /**
     * One INSERT of many rows.
     *
     * @param list<string>                $columns
     * @param list<list<string|int|bool>> $rows
     * @param array<int, ParameterType>   $types   by the position of the column
     */
    private static function insert(Connection $connection, string $table, array $columns, array $rows, array $types): void
    {
        $values = [];
        $parameters = [];
        $parameterTypes = [];
        $row = '('.implode(', ', array_fill(0, \count($columns), '?')).')';
        foreach ($rows as $cells) {
            $values[] = $row;
            foreach ($cells as $at => $cell) {
                $parameters[] = $cell;
                $parameterTypes[] = $types[$at] ?? ParameterType::STRING;
            }
        }
        $connection->executeStatement(\sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', $values)), $parameters, $parameterTypes);
    }

    /**
     * @return list<string>
     */
    private function deals(): array
    {
        $writes = $this->service(DealWrites::class);
        $ids = [];
        for ($i = 1; $i <= $this->size->deals; ++$i) {
            $ids[] = $writes->create(new CreateDealInput(\sprintf('Example deal %04d', $i), $this->pick($this->customers), description: 'An example deal of the performance baseline.'))->id();
            $this->step('deals', $i, $this->size->deals);
        }

        return $ids;
    }

    /**
     * Notes, done as they are written: every other one about a deal, the rest about a contact.
     *
     * @param list<string> $deals
     */
    private function activities(array $deals): void
    {
        $writes = $this->service(ActivityWrites::class);
        $note = $this->em->getRepository(ActivityType::class)->findOneBy(['key' => 'note']) ?? throw new \LogicException('The note activity type is missing.');
        $typeId = $note->id();
        for ($i = 1; $i <= $this->size->activities; ++$i) {
            $link = 0 === $i % 2 && [] !== $deals ? ['kind' => SalesRecords::KIND, 'id' => $deals[$i % \count($deals)]] : ['kind' => 'contact', 'id' => $this->pick($this->customers)];
            $writes->create(new CreateActivityInput($typeId, \sprintf('Example note %05d', $i), 'An example note of the performance baseline.', links: [$link]));
            $this->step('activities', $i, $this->size->activities);
        }
    }

    /**
     * Reports progress, and after each batch clears the entity manager and empties the
     * in-memory queues of the test environment, which would otherwise keep every
     * entity and every message.
     */
    private function step(string $type, int $done, int $total): void
    {
        if (0 === $done % self::BATCH || $done === $total) {
            $this->em->clear();
            foreach (self::TRANSPORTS as $name) {
                $id = 'messenger.transport.'.$name;
                if ($this->container->has($id) && ($transport = $this->container->get($id)) instanceof InMemoryTransport) {
                    $transport->reset();
                }
            }
            gc_collect_cycles();
            if ($this->progress instanceof \Closure) {
                ($this->progress)($type, $done, $total);
            }
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function timed(string $type, \Closure $work): mixed
    {
        $start = hrtime(true);
        try {
            return $work();
        } finally {
            $this->seconds[$type] = (hrtime(true) - $start) / 1e9;
        }
    }

    /**
     * @param list<string> $ids
     */
    private function pick(array $ids): string
    {
        if ([] === $ids) {
            throw new \LogicException('There is no contact to pick.');
        }

        return $ids[$this->random->getInt(0, \count($ids) - 1)];
    }

    private function address(int $n): Address
    {
        return new Address(\sprintf('Example Street %d', $n), 'Example City', \sprintf('1%02d 00', $n % 100), 'CZ');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        return self::serviceOf($this->container, $class);
    }

    /**
     * The test service container of a booted kernel of the test environment.
     */
    public static function testContainer(KernelInterface $kernel): ContainerInterface
    {
        return self::containerOf($kernel->getContainer(), 'test.service_container');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function serviceOf(ContainerInterface $container, string $class): object
    {
        $service = $container->get($class);
        if (!$service instanceof $class) {
            throw new \LogicException(\sprintf('The container has no %s; seed through the test service container.', $class));
        }

        return $service;
    }

    // The id is a parameter: PHPStan reads the services of the dev container, which has no test.service_container.
    private static function containerOf(ContainerInterface $container, string $id): ContainerInterface
    {
        $found = $container->get($id);

        return $found instanceof ContainerInterface ? $found : throw new \LogicException('Boot the test environment: only it has the test service container.');
    }
}
