<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Idoklad\Application;

use App\Idoklad\Application\ConnectIdoklad;
use App\Idoklad\Application\IdokladAccessDenied;
use App\Idoklad\Application\IdokladConnections;
use App\Idoklad\Application\IdokladFailure;
use App\Idoklad\Application\IdokladFiles;
use App\Idoklad\Application\IdokladJobRunner;
use App\Idoklad\Application\IdokladJobs;
use App\Idoklad\Application\IdokladNotConnected;
use App\Idoklad\Application\IdokladSnapshot;
use App\Idoklad\Application\IdokladTariffWithoutApi;
use App\Idoklad\Application\Import\RunIdokladImport;
use App\Idoklad\Application\PullIdokladSnapshot;
use App\Idoklad\Domain\IdokladJob;
use App\Idoklad\Domain\IdokladJobKind;
use App\Idoklad\Domain\IdokladJobState;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Infrastructure\Http\HttpIdokladClient;
use App\Idoklad\Infrastructure\Http\HttpIdokladSession;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\ReminderSettings;
use App\Invoicing\Domain\FirmReminders;
use App\Party\Application\LegalEntities;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class IdokladJobRunnerTest extends KernelTestCase
{
    private const string PDF = "%PDF-1.7\nexample";

    private EntityManagerInterface $em;
    private MockClock $clock;
    private LegalEntity $firm;

    /** @var array<string, list<ResponseInterface>> answers by path, the first one next; a list not named is empty */
    private array $answers = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get('doctrine.orm.entity_manager');
        $tool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $container->get(TenantContext::class)->switchTo(TenantId::generate());
        $this->clock = new MockClock('2026-11-06 10:00:00');

        $this->firm = new LegalEntity('Example s.r.o.', new RegistrationNumber('CZ', '12345678'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em->persist($this->firm);
        $this->em->flush();
    }

    public function testAPullDownloadsThePdfsBatchByBatchAndCountsTheirRequests(): void
    {
        $this->connect();
        $invoices = array_map(static fn (int $id): array => ['Id' => $id, 'DocumentNumber' => (string) $id], range(1, IdokladJobRunner::PDF_BATCH + 1));
        $this->answers['/v3/IssuedInvoices'] = [$this->page($invoices)];
        $job = $this->job(IdokladJobKind::Pull);

        $this->runner()->process($job);

        $this->em->clear();
        $stored = $this->storedJob($job);
        self::assertSame(IdokladJobState::Done, $stored->state());
        self::assertEquals(new \DateTimeImmutable('2026-11-06 10:00:00'), $stored->startedAt());
        self::assertNull($stored->error());
        $pull = $this->em->find(IdokladPull::class, (string) $stored->resultId());
        self::assertInstanceOf(IdokladPull::class, $pull);
        self::assertSame(14 + IdokladJobRunner::PDF_BATCH + 1, $pull->requestsUsed(), 'One request a list, then one a PDF in both batches.');
        self::assertSame([], $this->snapshot()->withoutPdf($this->storedFirm(), 10));
    }

    public function testAPullOfChangesWithoutAnEarlierPullOrCredentialsFailsBeforeAPullIsStored(): void
    {
        $job = $this->job(IdokladJobKind::Changes);

        $this->runner()->process($job);

        $this->em->clear();
        $stored = $this->storedJob($job);
        self::assertSame(IdokladJobState::Failed, $stored->state());
        self::assertSame(IdokladJobRunner::NOT_CONNECTED, $stored->error());
        self::assertNull($stored->resultId());
    }

    public function testAFailedPullOfChangesNamesThePullThatFailed(): void
    {
        $this->connect();
        $this->runner()->process($this->job(IdokladJobKind::Pull));
        $this->em->clear();
        $this->clock->sleep(60);
        $this->answers['/v3/Contacts'] = [new JsonMockResponse(['IsSuccess' => false], ['http_code' => 402])];
        $job = $this->job(IdokladJobKind::Changes);

        $this->runner()->process($job);

        $this->em->clear();
        $stored = $this->storedJob($job);
        self::assertSame(IdokladJobRunner::TARIFF, $stored->error());
        $pull = $this->em->find(IdokladPull::class, (string) $stored->resultId());
        self::assertInstanceOf(IdokladPull::class, $pull);
        self::assertFalse($pull->succeeded());
        self::assertSame('2026-11-06 10:01:00', $pull->startedAt()->format('Y-m-d H:i:s'));
    }

    public function testARunThatFailedLeavesTheDayRemindersStartFrom(): void
    {
        $job = $this->job(IdokladJobKind::Run, new \DateTimeImmutable('2026-11-02'));

        $this->runner()->process($job);

        $this->em->clear();
        self::assertSame(RunIdokladImport::NO_SNAPSHOT, $this->storedJob($job)->error());
        self::assertNull($this->em->getRepository(FirmReminders::class)->findOneBy(['firm' => $this->firm->id()])?->enabledOn());
    }

    public function testAJobGoneBeforeItEndsIsLeftAlone(): void
    {
        $job = $this->job(IdokladJobKind::Check);
        $container = self::getContainer();
        $real = $container->get(IdokladJobs::class);
        $jobs = new class($real) implements IdokladJobs {
            private int $finds = 0;

            public function __construct(private readonly IdokladJobs $real)
            {
            }

            public function add(IdokladJob $job): void
            {
                $this->real->add($job);
            }

            public function find(string $id): ?IdokladJob
            {
                return 0 === $this->finds++ ? $this->real->find($id) : null;
            }

            public function latest(LegalEntity $firm): ?IdokladJob
            {
                return $this->real->latest($firm);
            }

            public function busy(LegalEntity $firm): ?IdokladJob
            {
                return $this->real->busy($firm);
            }
        };

        $this->runner($jobs)->process($job);

        $this->em->clear();
        self::assertSame(IdokladJobState::Running, $this->storedJob($job)->state());
    }

    public function testEveryFailureHasTheTextAScreenShows(): void
    {
        self::assertSame(IdokladJobRunner::ACCESS_DENIED, IdokladJobRunner::errorOf(new IdokladAccessDenied('denied')));
        self::assertSame(IdokladJobRunner::TARIFF, IdokladJobRunner::errorOf(new IdokladTariffWithoutApi('tariff')));
        self::assertSame(IdokladJobRunner::NOT_CONNECTED, IdokladJobRunner::errorOf(new IdokladNotConnected('missing')));
        self::assertSame('iDoklad said no.', IdokladJobRunner::errorOf(new IdokladFailure('iDoklad said no.')));
    }

    private function connect(): void
    {
        self::getContainer()->get(ConnectIdoklad::class)->connect($this->firm, 'example-client', 'example-client-secret-0123456789');
    }

    private function job(IdokladJobKind $kind, ?\DateTimeImmutable $remindFrom = null): string
    {
        $job = new IdokladJob($this->storedFirm(), $kind, new \DateTimeImmutable('2026-11-06 09:59:00'), $remindFrom);
        self::getContainer()->get(TransactionalWork::class)->run(fn () => self::getContainer()->get(IdokladJobs::class)->add($job));

        return $job->id();
    }

    private function runner(?IdokladJobs $jobs = null): IdokladJobRunner
    {
        $container = self::getContainer();

        return new IdokladJobRunner(
            $jobs ?? $container->get(IdokladJobs::class),
            $this->pull(),
            $this->snapshot(),
            $container->get(RunIdokladImport::class),
            $container->get(ReminderSettings::class),
            $container->get(LegalEntities::class),
            $container->get(TransactionalWork::class),
            $this->clock,
        );
    }

    private function pull(): PullIdokladSnapshot
    {
        $http = new MockHttpClient(function (string $method, string $url): ResponseInterface {
            if (HttpIdokladSession::TOKEN_URL === $url) {
                return new JsonMockResponse(['access_token' => 'example-token', 'expires_in' => 3600]);
            }
            $path = (string) parse_url($url, \PHP_URL_PATH);
            if (str_starts_with($path, '/v3/Reports/')) {
                return $this->answer(base64_encode(self::PDF));
            }

            $answers = $this->answers[$path] ?? [];
            $answer = array_shift($answers) ?? $this->page([]);
            $this->answers[$path] = $answers;

            return $answer;
        });
        $container = self::getContainer();

        return new PullIdokladSnapshot(
            $container->get(IdokladConnections::class),
            $container->get(MailSecrets::class),
            new HttpIdokladClient($http, $this->clock),
            $this->snapshot(),
            $container->get(IdokladFiles::class),
            $container->get(TransactionalWork::class),
            $this->clock,
        );
    }

    /**
     * @param list<array<mixed>> $items
     */
    private function page(array $items): JsonMockResponse
    {
        return $this->answer(['Items' => $items, 'TotalItems' => \count($items), 'TotalPages' => 1]);
    }

    private function answer(mixed $data): JsonMockResponse
    {
        return new JsonMockResponse(['Data' => $data, 'ErrorCode' => 0, 'IsSuccess' => true, 'Message' => '', 'StatusCode' => 200]);
    }

    private function storedJob(string $id): IdokladJob
    {
        return $this->em->find(IdokladJob::class, $id) ?? self::fail('The job is stored.');
    }

    private function storedFirm(): LegalEntity
    {
        return $this->em->find(LegalEntity::class, $this->firm->id()) ?? self::fail('The firm is stored.');
    }

    private function snapshot(): IdokladSnapshot
    {
        return self::getContainer()->get(IdokladSnapshot::class);
    }
}
