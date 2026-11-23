<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Idoklad\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Domain\Role;
use Foresi\Idoklad\Application\ConnectIdoklad;
use Foresi\Idoklad\Application\IdokladJobRunner;
use Foresi\Idoklad\Application\Message\ProcessIdokladJob;
use Foresi\Idoklad\Domain\IdokladConnection;
use Foresi\Idoklad\Domain\IdokladImportRun;
use Foresi\Idoklad\Domain\IdokladJob;
use Foresi\Idoklad\Domain\IdokladJobKind;
use Foresi\Idoklad\Domain\IdokladJobState;
use Foresi\Idoklad\Domain\IdokladPull;
use Foresi\Idoklad\Domain\IdokladResource;
use Foresi\Idoklad\Domain\ImportMode;
use Foresi\Idoklad\Domain\ImportReport;
use Foresi\Party\Domain\Address;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatStatus;
use Foresi\Tests\Identity\Support\SignedInBrowser;
use Foresi\Tests\Support\Messenger\RunMessages;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class IdokladImportControllerTest extends WebTestCase
{
    private const string SECRET = 'example-client-secret-0123456789';

    private KernelBrowser $client;
    private SignedInBrowser $browser;
    private LegalEntity $firm;

    /** The status iDoklad answers its API requests with. */
    private int $apiStatus = 200;
    private int $tokenStatus = 200;

    /** Whether iDoklad's API fails as nothing expects. */
    private bool $unexpected = false;

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->browser = new SignedInBrowser($this->client, $this->em());
        Clock::set(new MockClock('2026-11-06 12:00:00'));
        self::getContainer()->set('http_client', new MockHttpClient(fn (string $method, string $url): ResponseInterface => $this->answer($method, $url)));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testConnectingSavesTheKeysAndTriesThem(): void
    {
        $this->signedInFirm();

        $this->client->request('GET', $this->url('/connect'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Settings → Applications → API');
        self::assertSelectorTextContains('main', 'Foresi only reads from iDoklad');

        $this->submitKeys('example-client', self::SECRET);

        self::assertResponseRedirects($this->url('/pull'));
        self::assertContains('GET https://api.idoklad.cz/v3/Contacts?page=1&pagesize=1', $this->sent);
        $connection = $this->connection();
        self::assertInstanceOf(IdokladConnection::class, $connection);
        self::assertSame('example-client', $connection->clientId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'iDoklad is connected.');
        self::assertSelectorTextContains('[data-quota]', '0 of 7,500 requests used');
    }

    public function testEmptyKeysAreRefusedAtTheirFields(): void
    {
        $this->signedInFirm();

        $this->submitKeys('', ' ');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#client_id-error');
        self::assertSelectorExists('#client_secret-error');
        self::assertNull($this->connection());
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function refusals(): iterable
    {
        yield 'wrong keys' => [401, 200, 'iDoklad refused the client ID or the client secret.'];
        yield 'a tariff without the API' => [200, 402, 'The tariff of this iDoklad account has no API access'];
        yield 'an answer it cannot give' => [200, 500, 'iDoklad answered with status 500.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testARefusalOfIDokladIsShownClearly(int $tokenStatus, int $apiStatus, string $message): void
    {
        $this->signedInFirm();
        $this->tokenStatus = $tokenStatus;
        $this->apiStatus = $apiStatus;

        $this->submitKeys('example-client', self::SECRET);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-connect-error]', $message);
        self::assertInstanceOf(IdokladConnection::class, $this->connection(), 'The keys stay, to try again once the tariff allows it.');
    }

    public function testDisconnectingDeletesTheKeys(): void
    {
        $this->connectedFirm();

        $this->client->request('GET', $this->url('/pull'));
        self::assertSelectorExists('form[action$="/disconnect"][data-turbo-confirm][data-confirm-tone="danger"]');
        $this->client->request('POST', $this->url('/disconnect'), ['_token' => $this->token()]);

        self::assertResponseRedirects($this->url('/connect'));
        self::assertNull($this->connection());
    }

    public function testAPullIsABackgroundJobWhosePageShowsItsState(): void
    {
        $this->connectedFirm();

        $this->client->request('POST', $this->url('/pull'), ['_token' => $this->token()]);

        self::assertResponseRedirects($this->url());
        $waiting = [...$this->transport()->get()];
        self::assertCount(1, $waiting);
        self::assertInstanceOf(ProcessIdokladJob::class, $waiting[0]->getMessage());
        $this->client->request('GET', $this->url());
        self::assertSelectorTextContains('[data-job-state]', 'Waiting');
        self::assertSelectorExists('turbo-frame#idoklad-progress[data-controller="import-progress"]');
        $this->client->request('GET', $this->url('/progress'));
        self::assertSelectorExists('[data-progress-active="true"][data-progress-state="pending"]');
        $this->client->request('GET', $this->url('/pull'));
        self::assertResponseRedirects($this->url());

        // Asking again while it waits gives the same job.
        $this->client->request('POST', $this->url('/pull'), ['_token' => $this->token()]);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM idoklad_job'));

        // A request resets the in-memory queue, so the worker gets the message as it was sent.
        $this->messages()->redeliver($waiting[0]);

        $job = $this->latestJob();
        self::assertSame(IdokladJobState::Done, $job->state());
        self::assertSame(IdokladJobKind::Pull, $job->kind());
        self::assertNotNull($job->resultId());
        $this->client->request('GET', $this->url());
        self::assertResponseRedirects($this->url('/pull'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-pull-state]', 'Done');
        self::assertSelectorTextContains('main', 'Contacts');
        self::assertSelectorTextContains('[data-quota]', 'of 7,500 requests used');
        self::assertSelectorTextContains('main', 'The limit depends on the tariff');
        self::assertSelectorTextContains('main', 'Download changes');

        // A second delivery of the message finds the job ended and does nothing.
        $this->sent = [];
        $this->messages()->redeliver($waiting[0]);
        self::assertSame([], $this->sent());
    }

    public function testASecondPullAsksOnlyForChanges(): void
    {
        $this->connectedFirm();
        $this->client->request('POST', $this->url('/pull'), ['_token' => $this->token()]);
        $this->messages()->run();
        $this->sent = [];

        $this->client->request('POST', $this->url('/pull'), ['_token' => $this->token(), 'changes' => '1']);
        $this->messages()->run();

        self::assertSame(IdokladJobKind::Changes, $this->latestJob()->kind());
        self::assertNotEmpty(array_filter($this->sent(), static fn (string $request): bool => str_contains($request, 'DateLastChange')));
    }

    public function testAFailedPullSaysWhy(): void
    {
        $this->connectedFirm();
        $this->apiStatus = 402;

        $this->client->request('POST', $this->url('/pull'), ['_token' => $this->token()]);
        $this->messages()->run();

        $job = $this->latestJob();
        self::assertSame(IdokladJobState::Failed, $job->state());
        self::assertSame(IdokladJobRunner::TARIFF, $job->error());
        $this->client->request('GET', $this->url('/pull'));
        self::assertSelectorTextContains('[data-job-error]', 'has no API access');
    }

    public function testARunWithoutTheVatPayerFieldTakesTheDayOfTheSnapshot(): void
    {
        $this->connectedFirm();
        $this->storeRun(ImportMode::Check, new ImportReport(), $this->pull());

        $this->client->request('POST', $this->url('/run'), ['_token' => $this->token(), 'remind_from' => '2026-11-02']);

        self::assertResponseRedirects($this->url());
        self::assertNull($this->latestJob()->options());
    }

    public function testAnUnexpectedFailureEndsTheJobAndTravelsOn(): void
    {
        $this->connectedFirm();
        $this->unexpected = true;

        $this->client->request('POST', $this->url('/pull'), ['_token' => $this->token()]);
        try {
            $this->messages()->run();
            self::fail('The failure travels on, so the worker logs it.');
        } catch (\Throwable $failure) {
            self::assertStringContainsString('Example unexpected failure.', $failure->getMessage().' '.$failure->getPrevious()?->getMessage());
        }

        $job = $this->latestJob();
        self::assertSame(IdokladJobState::Failed, $job->state());
        self::assertSame(IdokladJobRunner::UNEXPECTED, $job->error());
        $this->client->request('GET', $this->url('/pull'));
        self::assertSelectorTextContains('[data-job-error]', 'The job stopped on an unexpected error.');
    }

    public function testTheHistoryListsPullsAndRuns(): void
    {
        $this->connectedFirm();
        $this->storeRun(ImportMode::Check, new ImportReport(), $this->pull());

        $this->client->request('GET', $this->url('/history'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main table', 'Whole account');
        self::assertSelectorTextContains('main table', 'Check');
    }

    public function testTheImportHistoryLeadsToTheMoveAndOneFirmOpensAtOnce(): void
    {
        $this->signedInFirm();

        $this->client->request('GET', '/imports');
        self::assertSelectorExists('a[href="/import/idoklad"]');
        $this->client->request('GET', '/import/idoklad');

        self::assertResponseRedirects($this->url());
        $this->client->followRedirect();
        self::assertResponseRedirects($this->url('/connect'));
    }

    public function testTheMoveOpensTheInstanceFirmAndWithoutOneSaysSo(): void
    {
        $this->browser->seed();
        $this->browser->signIn();

        $this->client->request('GET', '/import/idoklad');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No firm yet');

        $this->firm = $this->firm();
        $this->client->request('GET', '/import/idoklad');
        self::assertResponseRedirects($this->url());
    }

    public function testOnlyTheOwnerMovesFromIDoklad(): void
    {
        $this->browser->seed(role: Role::Member);
        $firm = $this->firm();
        $this->browser->signIn();

        $this->client->request('GET', '/imports');
        self::assertSelectorNotExists('a[href="/import/idoklad"]');
        foreach (['', '/connect', '/pull', '/check', '/history'] as $path) {
            $this->client->request('GET', '/import/idoklad/'.$firm->id().$path);
            self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('GET', '/import/idoklad');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownRunIsNotFound(): void
    {
        $this->signedInFirm();

        $this->client->request('GET', $this->url('/runs/0190f5c4-6b1e-7c3a-9f7e-1a2b3c4d5e6f'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAWrongTokenIsRefused(): void
    {
        $this->connectedFirm();

        $this->client->request('POST', $this->url('/pull'), ['_token' => 'wrong']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->messages()->pending());
    }

    /**
     * @return list<string> the requests sent to iDoklad, as method and URL
     */
    private function sent(): array
    {
        return $this->sent;
    }

    private function submitKeys(string $clientId, string $secret): void
    {
        $this->client->request('POST', $this->url('/connect'), ['_token' => $this->token(), 'client_id' => $clientId, 'client_secret' => $secret]);
    }

    private function answer(string $method, string $url): ResponseInterface
    {
        $this->sent[] = $method.' '.urldecode($url);
        if (str_starts_with($url, 'https://identity.idoklad.cz/')) {
            return 200 === $this->tokenStatus ? new JsonMockResponse(['access_token' => 'example-token', 'expires_in' => 3600]) : new MockResponse('', ['http_code' => $this->tokenStatus]);
        }
        if ($this->unexpected) {
            throw new \LogicException('Example unexpected failure.');
        }
        if (200 !== $this->apiStatus) {
            return new MockResponse('', ['http_code' => $this->apiStatus]);
        }
        $items = str_contains($url, '/v3/Contacts?') ? [['Id' => 501, 'CompanyName' => 'Example Trading s.r.o.', 'Metadata' => ['DateLastChange' => '2026-11-01T10:00:00.000']]] : [];

        return new JsonMockResponse(['Data' => ['Items' => $items, 'TotalItems' => \count($items), 'TotalPages' => 1], 'IsSuccess' => true, 'StatusCode' => 200]);
    }

    private function signedInFirm(): void
    {
        $this->browser->seed();
        $this->browser->signIn();
        $this->firm = $this->firm();
    }

    private function connectedFirm(): void
    {
        $this->signedInFirm();
        $this->fresh(fn () => self::getContainer()->get(ConnectIdoklad::class)->connect('example-client', self::SECRET));
    }

    private function firm(): LegalEntity
    {
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em()->persist($firm);
        $this->em()->flush();

        return $firm;
    }

    private function pull(): IdokladPull
    {
        return $this->fresh(function (): IdokladPull {
            $pull = new IdokladPull(new \DateTimeImmutable('2026-11-06 09:00:00'));
            $pull->counted(IdokladResource::Contacts, 1);
            $pull->finish(new \DateTimeImmutable('2026-11-06 09:05:00'), 3);
            $this->em()->persist($pull);
            $this->em()->flush();

            return $pull;
        });
    }

    private function storeRun(ImportMode $mode, ImportReport $report, IdokladPull $pull): void
    {
        $this->fresh(function () use ($mode, $report, $pull): void {
            $run = new IdokladImportRun($mode, new \DateTimeImmutable('2026-11-06 10:00:00'));
            $run->readFrom($pull);
            $run->finish(new \DateTimeImmutable('2026-11-06 10:01:00'), $report);
            $this->em()->persist($run);
            $this->em()->flush();
        });
    }

    private function connection(): ?IdokladConnection
    {
        return $this->fresh(fn (): ?IdokladConnection => $this->em()->getRepository(IdokladConnection::class)->findOneBy([]));
    }

    private function latestJob(): IdokladJob
    {
        return $this->fresh(fn (): IdokladJob => $this->em()->getRepository(IdokladJob::class)->findOneBy([], ['requestedAt' => 'DESC', 'id' => 'DESC']) ?? self::fail('No job was asked for.'));
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function fresh(callable $work): mixed
    {
        $this->em()->clear();
        try {
            return $work();
        } finally {
            $this->em()->clear();
        }
    }

    private function messages(): RunMessages
    {
        return new RunMessages($this->transport(), self::getContainer()->get('messenger.default_bus'));
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function url(string $path = ''): string
    {
        return '/import/idoklad/'.$this->firm->id().$path;
    }

    private function token(): string
    {
        // As a browser has it: from a form of the move.
        return (string) $this->client->request('GET', $this->url('/connect'))->filter('form[action$="/connect"] input[name="_token"]')->attr('value');
    }

    private function rows(string $sql): int
    {
        $count = $this->em()->getConnection()->fetchOne($sql);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.entity_manager');
    }
}
