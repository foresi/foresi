<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Idoklad\Application;

use App\Idoklad\Application\ConnectIdoklad;
use App\Idoklad\Application\IdokladConnections;
use App\Idoklad\Application\IdokladFailure;
use App\Idoklad\Application\IdokladFiles;
use App\Idoklad\Application\IdokladNotConnected;
use App\Idoklad\Application\IdokladSnapshot;
use App\Idoklad\Application\IdokladTariffWithoutApi;
use App\Idoklad\Application\PullIdokladSnapshot;
use App\Idoklad\Domain\IdokladConnection;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\InvalidIdokladConnection;
use App\Idoklad\Infrastructure\Http\HttpIdokladClient;
use App\Idoklad\Infrastructure\Http\HttpIdokladSession;
use App\Invoicing\Application\MailSecrets;
use App\Party\Application\TransactionalWork;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class IdokladSnapshotTest extends KernelTestCase
{
    private const string SECRET = 'example-client-secret-0123456789';
    private const string PDF = "%PDF-1.7\nexample";

    private EntityManagerInterface $em;
    private ConnectIdoklad $connect;
    private MockClock $clock;
    private LegalEntity $firm;

    /** @var array<string, list<ResponseInterface>> answers by path, the first one next */
    private array $answers = [];

    /** @var list<string> */
    private array $sent = [];

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
        $this->connect = $container->get(ConnectIdoklad::class);
        $this->clock = new MockClock('2026-11-06 10:00:00');

        $this->firm = $this->newFirm('12345678');
        $this->em->flush();
    }

    public function testTheSecretIsStoredSealedAndOutOfTheAuditLog(): void
    {
        $this->connect->connect($this->firm, ' example-client ', ' '.self::SECRET.' ');
        $this->em->clear();

        $connection = $this->connection();
        $rows = $connection->fetchAllAssociative('SELECT * FROM idoklad_connection');
        $audit = $connection->fetchAllAssociative('SELECT * FROM audit_entry');
        self::assertCount(1, $rows);
        self::assertSame('example-client', $rows[0]['client_id']);
        self::assertIsString($rows[0]['secret_sealed']);
        $stored = json_encode([$rows, $audit], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString(self::SECRET, $stored);
        self::assertStringNotContainsString(base64_encode(self::SECRET), $stored);
        $auditText = json_encode($audit, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString($rows[0]['secret_sealed'], $auditText);
        self::assertStringContainsString('example-client', $auditText, 'The rest of the connection is audited.');

        $stored = $this->stored()->for($this->storedFirm());
        self::assertInstanceOf(IdokladConnection::class, $stored);
        self::assertSame(self::SECRET, self::getContainer()->get(MailSecrets::class)->open($stored->sealedSecret()));
    }

    public function testConnectingAgainReplacesTheCredentialsAndDisconnectingDeletesThem(): void
    {
        $first = $this->connect->connect($this->firm, 'example-client', self::SECRET);
        $second = $this->connect->connect($this->firm, 'other-client', 'other-secret');
        self::assertSame($first->id(), $second->id());
        $this->em->clear();

        $stored = $this->stored()->for($this->storedFirm());
        self::assertInstanceOf(IdokladConnection::class, $stored);
        self::assertSame('other-client', $stored->clientId());
        self::assertSame('other-secret', self::getContainer()->get(MailSecrets::class)->open($stored->sealedSecret()));

        self::assertTrue($this->connect->disconnect($this->storedFirm()));
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM idoklad_connection'));
        self::assertFalse($this->connect->disconnect($this->storedFirm()));
        self::assertNull($this->stored()->for($this->storedFirm()));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidCredentials(): iterable
    {
        yield 'no secret' => ['example-client', '  ', 'The iDoklad connection needs a client secret.'];
        yield 'no client id' => [' ', self::SECRET, 'The iDoklad client id is one line of up to 255 characters.'];
        yield 'a line break' => ["example\nclient", self::SECRET, 'The iDoklad client id is one line of up to 255 characters.'];
        yield 'too long' => [str_repeat('c', 256), self::SECRET, 'The iDoklad client id is one line of up to 255 characters.'];
    }

    #[DataProvider('invalidCredentials')]
    public function testInvalidCredentialsAreRefused(string $clientId, string $secret, string $message): void
    {
        try {
            $this->connect->connect($this->firm, $clientId, $secret);
            self::fail('The credentials are refused.');
        } catch (InvalidIdokladConnection $invalid) {
            self::assertSame($message, $invalid->getMessage());
        }
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM idoklad_connection'));
        new IdokladConnection($this->firm, str_repeat('c', 255), 'sealed');
    }

    public function testASealedSecretIsRequired(): void
    {
        $this->expectException(InvalidIdokladConnection::class);
        $this->expectExceptionMessageIsOrContains('The iDoklad connection needs a client secret.');
        new IdokladConnection($this->firm, 'example-client', '');
    }

    public function testAPullStoresEveryRecordOfEveryListAndCountsThem(): void
    {
        $this->connect->connect($this->firm, 'example-client', self::SECRET);
        $this->answerAll();

        $pull = $this->pull()->pull($this->firm);

        self::assertTrue($pull->succeeded());
        self::assertSame([
            'Contacts' => 1, 'NumericSequences' => 1, 'IssuedInvoices' => 2, 'ProformaInvoices' => 2, 'IssuedTaxDocuments' => 0,
            'CreditNotes' => 0, 'RecurringInvoices' => 0, 'IssuedDocumentPayments' => 1, 'Logs' => 1, 'Countries' => 1,
            'Currencies' => 1, 'PaymentOptions' => 1, 'ConstantSymbols' => 1,
        ], $pull->counts());
        self::assertSame(14, $pull->requestsUsed(), 'One request a page: thirteen lists, one of them two pages long.');
        self::assertSame('2026-10-05T12:00:00', $pull->latestChange());
        self::assertNull($pull->changedSince());
        self::assertNull($pull->error());
        self::assertEquals(new \DateTimeImmutable('2026-11-06 10:00:00'), $pull->startedAt());
        self::assertNotNull($pull->finishedAt());
        self::assertContains('/v3/ProformaInvoices?page=2&pagesize=200', $this->sent);
        self::assertStringNotContainsString('filter', implode(' ', $this->sent));

        $this->em->clear();
        $firm = $this->storedFirm();
        $snapshot = $this->snapshot();
        $invoices = [...$snapshot->records($firm, IdokladResource::IssuedInvoices)];
        self::assertSame([101, 102], array_map(static fn (IdokladRecord $record): int => $record->externalId(), $invoices));
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/issued-invoices.json'), true, 32, \JSON_THROW_ON_ERROR);
        \assert(\is_array($fixture) && \is_array($fixture['Data']) && \is_array($fixture['Data']['Items']));
        self::assertSame($fixture['Data']['Items'][0], $invoices[0]->payload(), 'The record is kept as iDoklad returned it, lines included.');
        self::assertSame('2026-10-02T10:39:22.873', $invoices[0]->changedAt());
        self::assertSame(IdokladResource::IssuedInvoices, $invoices[0]->resource());
        self::assertEquals(new \DateTimeImmutable('2026-11-06 10:00:00'), $invoices[0]->pulledAt());
        self::assertSame('2026-10-04T08:00:00', $snapshot->record($firm, IdokladResource::Logs, 77)?->changedAt());
        self::assertNull($snapshot->record($firm, IdokladResource::Contacts, 102));
        self::assertSame($pull->id(), $snapshot->lastSucceededPull($firm)?->id());
        $stored = $this->em->find(IdokladPull::class, $pull->id());
        self::assertSame($pull->counts(), $stored?->counts());
        self::assertSame($firm->id(), $stored->firm()->id());
    }

    public function testADeltaPullFiltersTheListsThatCanAndReplacesChangedRecords(): void
    {
        $this->connect->connect($this->firm, 'example-client', self::SECRET);
        $this->answerAll();
        $first = $this->pull()->pull($this->firm);
        $since = $first->latestChange();
        self::assertNotNull($since);

        $this->sent = [];
        $this->answerAll(invoices: [['Id' => 101, 'DocumentNumber' => '20260001', 'Metadata' => ['DateLastChange' => '2026-10-07T09:00:00.5']]]);
        $this->clock->sleep(60);
        $delta = $this->pull()->pull($this->firm, $since);

        self::assertSame('2026-10-05T12:00:00', $delta->changedSince());
        self::assertSame('2026-10-07T09:00:00.5', $delta->latestChange());
        self::assertContains('/v3/IssuedInvoices?page=1&pagesize=200&filter=(DateLastChange~gt~2026-10-05T12:00:00)', $this->sent);
        self::assertContains('/v3/Contacts?page=1&pagesize=200&filter=(DateLastChange~gt~2026-10-05T12:00:00)', $this->sent);
        self::assertContains('/v3/Logs?page=1&pagesize=200&filter=(DateFrom~gt~2026-10-05T12:00:00)', $this->sent);
        self::assertContains('/v3/IssuedDocumentPayments?page=1&pagesize=200', $this->sent);
        self::assertContains('/v3/NumericSequences?page=1&pagesize=200', $this->sent);
        self::assertContains('/v3/Countries?page=1&pagesize=200', $this->sent);
        self::assertContains('/v3/Currencies?page=1&pagesize=200', $this->sent);

        $this->em->clear();
        $firm = $this->storedFirm();
        self::assertSame(2, $this->connection()->fetchOne("SELECT COUNT(*) FROM idoklad_record WHERE resource = 'IssuedInvoices'"));
        $changed = $this->snapshot()->record($firm, IdokladResource::IssuedInvoices, 101);
        self::assertSame(['Id' => 101, 'DocumentNumber' => '20260001', 'Metadata' => ['DateLastChange' => '2026-10-07T09:00:00.5']], $changed?->payload());
        self::assertEquals(new \DateTimeImmutable('2026-11-06 10:01:00'), $changed->pulledAt());
        self::assertSame($delta->id(), $this->snapshot()->lastSucceededPull($firm)?->id());
    }

    public function testADeltaPullNeedsAMomentAsIdokladWritesIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IdokladPull($this->firm, new \DateTimeImmutable(), '2026-10-05 12:00');
    }

    public function testAPullThatStopsRecordsWhyAndKeepsWhatItGot(): void
    {
        $this->connect->connect($this->firm, 'example-client', self::SECRET);
        $this->answerAll();
        $this->answers['/v3/IssuedInvoices'] = [new JsonMockResponse(['IsSuccess' => false], ['http_code' => 402])];

        try {
            $this->pull()->pull($this->firm);
            self::fail('A tariff without the API stops the pull.');
        } catch (IdokladTariffWithoutApi) {
        }

        $this->em->clear();
        $pulls = $this->connection()->fetchAllAssociative('SELECT * FROM idoklad_pull');
        self::assertCount(1, $pulls);
        self::assertSame('The iDoklad tariff of this account has no API access, or it expired.', $pulls[0]['error']);
        self::assertSame(3, $pulls[0]['requests_used']);
        self::assertNotNull($pulls[0]['finished_at']);
        self::assertIsString($pulls[0]['counts']);
        self::assertSame(['Contacts' => 1, 'NumericSequences' => 1], json_decode($pulls[0]['counts'], true));
        self::assertSame(2, $this->connection()->fetchOne('SELECT COUNT(*) FROM idoklad_record'));
        self::assertNull($this->snapshot()->lastSucceededPull($this->storedFirm()));
    }

    public function testARecordWithoutAnIdStopsThePullBeforeThePageIsStored(): void
    {
        $this->connect->connect($this->firm, 'example-client', self::SECRET);
        $this->answerAll(invoices: [['Id' => 103], ['DocumentNumber' => 'no id']]);

        try {
            $this->pull()->pull($this->firm);
            self::fail('A record without an id cannot be kept.');
        } catch (IdokladFailure $failure) {
            self::assertSame('iDoklad returned a record of IssuedInvoices without an id.', $failure->getMessage());
        }
        self::assertSame(0, $this->connection()->fetchOne("SELECT COUNT(*) FROM idoklad_record WHERE resource = 'IssuedInvoices'"));
        self::assertSame('iDoklad returned a record of IssuedInvoices without an id.', $this->connection()->fetchOne('SELECT error FROM idoklad_pull'));
    }

    public function testAFirmWithoutCredentialsCannotPull(): void
    {
        try {
            $this->pull()->pull($this->firm);
            self::fail('There is nothing to pull with.');
        } catch (IdokladNotConnected $missing) {
            self::assertSame('The firm has no iDoklad connection.', $missing->getMessage());
        }
        self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM idoklad_pull'));
        self::assertSame([], $this->sent);
    }

    public function testThePdfsOfPulledDocumentsAreDownloadedOnceUntilTheyChange(): void
    {
        $this->connect->connect($this->firm, 'example-client', self::SECRET);
        $this->answerAll();
        $this->pull()->pull($this->firm);
        $this->sent = [];
        foreach (['/v3/Reports/IssuedInvoice/101/Pdf', '/v3/Reports/IssuedInvoice/102/Pdf', '/v3/Reports/ProformaInvoice/201/Pdf'] as $path) {
            $this->answers[$path] = [$this->answer(base64_encode(self::PDF))];
        }

        $download = $this->pull()->downloadPdfs($this->firm, 3);

        self::assertSame(3, $download->downloaded);
        self::assertSame(3, $download->requestsUsed);
        self::assertSame(['/v3/Reports/IssuedInvoice/101/Pdf', '/v3/Reports/IssuedInvoice/102/Pdf', '/v3/Reports/ProformaInvoice/201/Pdf'], $this->sent);
        $this->em->clear();
        $firm = $this->storedFirm();
        $record = $this->snapshot()->record($firm, IdokladResource::IssuedInvoices, 101);
        self::assertNotNull($record?->pdfKey());
        self::assertSame(hash('sha256', self::PDF), $record->pdfSha256());
        self::assertSame(self::PDF, self::getContainer()->get(IdokladFiles::class)->read($record->tenantId()->toString(), $record->pdfKey(), $record->pdfSha256()));
        self::assertSame([202], array_map(static fn (IdokladRecord $record): int => $record->externalId(), $this->snapshot()->withoutPdf($firm, 10)));

        // A pull that brings the same record keeps its PDF; a changed one asks for it again.
        $this->answerAll(invoices: [$record->payload(), ['Id' => 102, 'DocumentNumber' => 'changed']]);
        $this->pull()->pull($firm);
        $this->em->clear();
        self::assertSame([102, 202], array_map(static fn (IdokladRecord $record): int => $record->externalId(), $this->snapshot()->withoutPdf($this->storedFirm(), 10)));
    }

    public function testADocumentThatIsNotAPdfStopsTheDownload(): void
    {
        $this->connect->connect($this->firm, 'example-client', self::SECRET);
        $this->answerAll();
        $this->pull()->pull($this->firm);
        $this->answers['/v3/Reports/IssuedInvoice/101/Pdf'] = [$this->answer(base64_encode('<html>'))];

        $this->expectException(IdokladFailure::class);
        $this->expectExceptionMessageIsOrContains('iDoklad returned a document that is not a PDF.');
        $this->pull()->downloadPdfs($this->firm);
    }

    public function testADocumentThatIsNotBase64StopsTheDownload(): void
    {
        $this->connect->connect($this->firm, 'example-client', self::SECRET);
        $this->answerAll();
        $this->pull()->pull($this->firm);
        $this->answers['/v3/Reports/IssuedInvoice/101/Pdf'] = [$this->answer('%PDF-not base64!')];

        $this->expectException(IdokladFailure::class);
        $this->expectExceptionMessageIsOrContains('iDoklad returned a document that is not a PDF.');
        $this->pull()->downloadPdfs($this->firm);
    }

    /**
     * @param list<array<mixed>>|null $invoices
     */
    private function answerAll(?array $invoices = null): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/issued-invoices.json'), true, 32, \JSON_THROW_ON_ERROR);
        \assert(\is_array($fixture));
        $this->answers = [
            '/v3/Contacts' => [$this->page([['Id' => 501, 'CompanyName' => 'Example s.r.o.', 'IdentificationNumber' => '12345678', 'Email' => 'billing@example.com', 'Metadata' => ['DateLastChange' => '2026-09-01T08:00:00']]])],
            '/v3/NumericSequences' => [$this->page([['Id' => 1, 'DocumentType' => 0, 'Name' => 'Invoices', 'NumberFormat' => 'YYYYNNNN', 'LastNumber' => 2, 'Year' => 2026]])],
            '/v3/IssuedInvoices' => [null === $invoices ? new JsonMockResponse($fixture) : $this->page($invoices)],
            '/v3/ProformaInvoices' => [
                $this->page([['Id' => 201, 'DocumentNumber' => 'ZF20260001', 'Metadata' => ['DateLastChange' => '2026-10-05T12:00:00']]], 2),
                $this->page([['Id' => 202, 'DocumentNumber' => 'ZF20260002', 'Metadata' => null]], 2),
            ],
            '/v3/IssuedTaxDocuments' => [$this->page([])],
            '/v3/CreditNotes' => [$this->page([])],
            '/v3/RecurringInvoices' => [$this->page([])],
            '/v3/IssuedDocumentPayments' => [$this->page([['Id' => 301, 'InvoiceId' => 102, 'DateOfPayment' => '2026-10-04T00:00:00', 'Metadata' => ['DateLastChange' => 'not a moment']]])],
            '/v3/Logs' => [$this->page([['Id' => 77, 'EntityType' => 0, 'ActionType' => 1, 'DateOfAction' => '2026-10-04T08:00:00']])],
            '/v3/Countries' => [$this->page([['Id' => 2, 'Code' => 'CZE', 'Name' => 'Česká republika', 'NameEnglish' => 'Czech Republic', 'DateLastChange' => '2026-01-01T00:00:00']])],
            '/v3/Currencies' => [$this->page([['Id' => 1, 'Code' => 'CZK', 'Symbol' => 'Kč', 'DateLastChange' => '2026-01-01T00:00:00']])],
            '/v3/PaymentOptions' => [$this->page([['Id' => 1, 'Code' => 'B', 'Name' => 'Převodem', 'IsDefault' => true, 'DateLastChange' => '2026-01-01T00:00:00']])],
            '/v3/ConstantSymbols' => [$this->page([['Id' => 7, 'Code' => '0308', 'Name' => 'Služby', 'CountryId' => 2, 'DateLastChange' => '2026-01-01T00:00:00']])],
        ];
    }

    private function pull(): PullIdokladSnapshot
    {
        $http = new MockHttpClient(function (string $method, string $url): ResponseInterface {
            if (HttpIdokladSession::TOKEN_URL === $url) {
                return new JsonMockResponse(['access_token' => 'example-token', 'expires_in' => 3600]);
            }
            $this->sent[] = (string) preg_replace('#^https://api\.idoklad\.cz#', '', urldecode($url));
            $path = (string) parse_url($url, \PHP_URL_PATH);

            return array_shift($this->answers[$path]) ?? new MockResponse('', ['http_code' => 404]);
        });
        $container = self::getContainer();

        return new PullIdokladSnapshot(
            $this->stored(),
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
    private function page(array $items, int $pages = 1): JsonMockResponse
    {
        return $this->answer(['Items' => $items, 'TotalItems' => \count($items), 'TotalPages' => $pages]);
    }

    private function answer(mixed $data): JsonMockResponse
    {
        return new JsonMockResponse(['Data' => $data, 'ErrorCode' => 0, 'IsSuccess' => true, 'Message' => '', 'StatusCode' => 200]);
    }

    private function newFirm(string $registrationNumber): LegalEntity
    {
        $firm = new LegalEntity('Example s.r.o.', new RegistrationNumber('CZ', $registrationNumber), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');
        $this->em->persist($firm);

        return $firm;
    }

    private function storedFirm(): LegalEntity
    {
        return $this->em->find(LegalEntity::class, $this->firm->id()) ?? self::fail('The firm is stored.');
    }

    private function stored(): IdokladConnections
    {
        return self::getContainer()->get(IdokladConnections::class);
    }

    private function snapshot(): IdokladSnapshot
    {
        return self::getContainer()->get(IdokladSnapshot::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
