<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Integration\Margin;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Margin\Domain\Allocation;
use Foresi\Margin\Domain\AllocationKind;
use Foresi\Margin\Domain\AllocationShare;
use Foresi\Margin\Domain\CostShare;
use Foresi\Margin\Domain\CostShareSource;
use Foresi\Margin\Domain\DocumentAllocation;
use Foresi\Margin\Domain\LineAllocation;
use Foresi\Margin\Domain\Recipient;
use Foresi\Party\Domain\Party;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Who bears the cost of a received document, over REST and MCP, with the
 * received documents Purchasing gives Margin: a line split by percent, the
 * whole document assigned and what is refused. Who may work with it at all is
 * in tests/Margin/Infrastructure.
 */
final class MarginApiTest extends ApiTestCase
{
    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('margin:write'), ApiScope::from('received_documents:write')]);
    }

    public function testALineSplitByPercentAnswersItsSharesAndTheReadAgrees(): void
    {
        $id = $this->recorded('FV-1');

        $this->send('PUT', $this->line($id, 'rate:21'), ['kind' => 'percent', 'shares' => [
            ['recipient' => $this->customerId, 'percent' => '33.33'],
            ['recipient' => 'own', 'percent' => '66.67'],
        ]]);

        self::assertResponseIsSuccessful();
        self::assertSame($id, $this->at('data', 'document_id'));
        self::assertSame('rate:21', $this->at('data', 'lines', 0, 'key'));
        self::assertSame(['amount_minor' => 100_000, 'currency' => 'CZK'], $this->at('data', 'lines', 0, 'base_czk'));
        self::assertSame('percent', $this->at('data', 'lines', 0, 'allocation', 'kind'));
        self::assertSame(['Example Customer a.s.', '33.33'], [$this->at('data', 'lines', 0, 'allocation', 'shares', 0, 'recipient_name'), $this->at('data', 'lines', 0, 'allocation', 'shares', 0, 'percent')]);
        self::assertSame([[$this->customerId, 'Example Customer a.s.', 33_330, 'line'], ['own', null, 66_670, 'line']], $this->shares());
        self::assertSame([], $this->at('data', 'known_gaps'));
        $written = $this->json()['data'];

        $this->get('/api/v1/margin/documents/'.$id, secret: $this->writer);
        self::assertSame($written, $this->json()['data']);
    }

    public function testAWholeDocumentAssignmentCountsForLinesWithoutTheirOwnMapping(): void
    {
        $id = $this->recorded('FV-2');
        $this->send('PUT', '/api/v1/received-documents/'.$id.'/lines', ['lines' => [
            ['text' => 'Hosting', 'base' => '700.00', 'vat_rate' => '21'],
            ['text' => 'Domain', 'base' => '300.00', 'vat_rate' => '21'],
        ]]);
        $hosting = $this->at('data', 'lines', 0, 'key');
        $domain = $this->at('data', 'lines', 1, 'key');
        self::assertIsString($hosting);
        self::assertIsString($domain);

        $this->send('POST', '/api/v1/margin/documents/'.$id.'/assign', ['recipient' => $this->customerId]);
        self::assertResponseIsSuccessful();
        self::assertSame(['recipient' => $this->customerId, 'recipient_name' => 'Example Customer a.s.'], $this->at('data', 'document_allocation'));
        self::assertSame([[$this->customerId, 'Example Customer a.s.', 70_000, 'document'], [$this->customerId, 'Example Customer a.s.', 30_000, 'document']], [$this->shareOf(0), $this->shareOf(1)]);

        $this->send('PUT', $this->line($id, $domain), ['kind' => 'whole', 'shares' => [['recipient' => 'own']]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['document', 'line'], [$this->at('data', 'lines', 0, 'shares', 0, 'source'), $this->at('data', 'lines', 1, 'shares', 0, 'source')], 'A line mapping beats the document mapping.');
        self::assertSame('own', $this->at('data', 'lines', 1, 'shares', 0, 'recipient'));
    }

    public function testNullClearsALineMappingAndADocumentAssignment(): void
    {
        $id = $this->recorded('FV-3');
        $this->send('POST', '/api/v1/margin/documents/'.$id.'/assign', ['recipient' => 'own']);
        $this->send('PUT', $this->line($id, 'rate:21'), ['kind' => 'whole', 'shares' => [['recipient' => $this->customerId]]]);
        self::assertSame('line', $this->at('data', 'lines', 0, 'shares', 0, 'source'));

        $this->send('PUT', $this->line($id, 'rate:21'), ['kind' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->at('data', 'lines', 0, 'allocation'));
        self::assertSame(['own', 'document'], [$this->at('data', 'lines', 0, 'shares', 0, 'recipient'), $this->at('data', 'lines', 0, 'shares', 0, 'source')]);
        self::assertSame(0, $this->em()->getRepository(LineAllocation::class)->count([]));

        $this->send('POST', '/api/v1/margin/documents/'.$id.'/assign', ['recipient' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->at('data', 'document_allocation'));
        self::assertSame([null, 'unassigned', 100_000], [$this->at('data', 'lines', 0, 'shares', 0, 'recipient'), $this->at('data', 'lines', 0, 'shares', 0, 'source'), $this->at('data', 'lines', 0, 'shares', 0, 'amount', 'amount_minor')]);
    }

    public function testAMappingStaysOnItsLineWhenAnEarlierLineIsRemoved(): void
    {
        $id = $this->recorded('FV-4');
        $this->send('PUT', '/api/v1/received-documents/'.$id.'/lines', ['lines' => [
            ['text' => 'Hosting', 'base' => '700.00', 'vat_rate' => '21'],
            ['text' => 'Domain', 'base' => '300.00', 'vat_rate' => '21'],
        ]]);
        $hosting = $this->at('data', 'lines', 0, 'key');
        $domain = $this->at('data', 'lines', 1, 'key');
        self::assertIsString($hosting);
        self::assertIsString($domain);
        $this->send('PUT', $this->line($id, $domain), ['kind' => 'whole', 'shares' => [['recipient' => $this->customerId]]]);

        $this->send('PUT', '/api/v1/received-documents/'.$id.'/lines', ['lines' => [['id' => $domain, 'text' => 'Domain', 'base' => '1000.00', 'vat_rate' => '21']]]);
        $this->get('/api/v1/margin/documents/'.$id, secret: $this->writer);

        self::assertSame([$domain, $this->customerId, 100_000], [$this->at('data', 'lines', 0, 'key'), $this->at('data', 'lines', 0, 'shares', 0, 'recipient'), $this->at('data', 'lines', 0, 'shares', 0, 'amount', 'amount_minor')]);
    }

    public function testWhatIsNotAnAllocationIsRefusedWithItsCode(): void
    {
        $id = $this->recorded('FV-5');
        $path = $this->line($id, 'rate:21');
        $stranger = Uuid::v7()->toRfc4122();

        $this->send('PUT', $path, ['kind' => 'whole', 'shares' => [['recipient' => $stranger]]]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'shares', 'reason' => 'unknown_contact']);

        $this->send('PUT', $path, ['kind' => 'whole', 'shares' => [['recipient' => 'somebody']]]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'shares', 'reason' => 'recipient_invalid']);

        $this->send('PUT', $path, ['kind' => 'percent', 'shares' => [['recipient' => 'own', 'percent' => '60'], ['recipient' => $this->customerId, 'percent' => '30']]]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'shares', 'reason' => 'percent_must_add_up']);

        $this->send('PUT', $path, ['kind' => 'fixed', 'shares' => [['recipient' => 'own', 'amount' => '-5.00'], ['recipient' => $this->customerId, 'rest' => true]]]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'shares', 'reason' => 'amount_invalid']);

        $this->send('PUT', $path, ['kind' => 'whole', 'shares' => [['recipient' => 'own'], ['recipient' => 'own']]]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'shares', 'reason' => 'whole_needs_one']);

        $archived = $this->flushed(function (): string {
            $party = $this->em()->find(Party::class, $this->customerId);
            self::assertInstanceOf(Party::class, $party);
            $party->archive(new \DateTimeImmutable());

            return $party->id();
        });
        $this->send('PUT', $path, ['kind' => 'whole', 'shares' => [['recipient' => $archived]]]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'shares', 'reason' => 'unknown_contact']);

        $this->send('POST', '/api/v1/margin/documents/'.$id.'/assign', ['recipient' => $stranger]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'recipient', 'reason' => 'unknown_contact']);

        self::assertSame(0, $this->em()->getRepository(LineAllocation::class)->count([]));
    }

    public function testAnUnknownDocumentOrALineKeyIsNotFound(): void
    {
        $id = $this->recorded('FV-6');
        $unknown = Uuid::v7()->toRfc4122();

        $this->send('PUT', $this->line($unknown, 'rate:21'), ['kind' => 'whole', 'shares' => [['recipient' => 'own']]]);
        $this->assertProblem(404, 'unknown_document');
        $this->send('POST', '/api/v1/margin/documents/'.$unknown.'/assign', ['recipient' => 'own']);
        $this->assertProblem(404, 'unknown_document');
        $this->get('/api/v1/margin/documents/'.$unknown, secret: $this->writer);
        $this->assertProblem(404, 'unknown_document');
        $this->get('/api/v1/margin/documents/not-an-id', secret: $this->writer);
        $this->assertProblem(404, 'unknown_document');

        $this->send('PUT', $this->line($id, 'rate:10'), ['kind' => 'whole', 'shares' => [['recipient' => 'own']]]);
        $this->assertProblem(404, 'unknown_line');
        $this->send('PUT', $this->line($id, 'rate:10'), ['kind' => null]);
        $this->assertProblem(404, 'unknown_line');
    }

    public function testTheToolsDoWhatTheEndpointsDoAndARepeatedKeyActsOnce(): void
    {
        $id = $this->recorded('FV-7');

        $set = $this->tool('foresi_set_line_allocation', ['document_id' => $id, 'line_key' => 'rate:21', 'kind' => 'percent', 'shares' => [['recipient' => 'own', 'percent' => '40'], ['recipient' => $this->customerId, 'percent' => '60']], 'idempotency_key' => 'set-1']);
        self::assertFalse($set['isError'] ?? false);
        self::assertSame(['own', 40_000], [$this->in($set, 'structuredContent', 'data', 'lines', 0, 'shares', 0, 'recipient'), $this->in($set, 'structuredContent', 'data', 'lines', 0, 'shares', 0, 'amount', 'amount_minor')]);
        $repeated = $this->tool('foresi_set_line_allocation', ['document_id' => $id, 'line_key' => 'rate:21', 'kind' => 'percent', 'shares' => [['recipient' => 'own', 'percent' => '40'], ['recipient' => $this->customerId, 'percent' => '60']], 'idempotency_key' => 'set-1']);
        self::assertSame($this->in($set, 'structuredContent', 'data'), $this->in($repeated, 'structuredContent', 'data'));
        self::assertTrue($this->in($repeated, 'structuredContent', 'idempotent_replayed'));
        self::assertSame(2, $this->em()->getRepository(CostShare::class)->count([]));

        $assigned = $this->tool('foresi_assign_received_document', ['document_id' => $id, 'recipient' => $this->customerId, 'idempotency_key' => 'assign-1']);
        self::assertSame($this->customerId, $this->in($assigned, 'structuredContent', 'data', 'document_allocation', 'recipient'));

        $read = $this->tool('foresi_get_document_allocation', ['document_id' => $id]);
        self::assertFalse($read['isError'] ?? false);
        self::assertSame($this->in($assigned, 'structuredContent', 'data'), $this->in($read, 'structuredContent', 'data'));
    }

    public function testTheToolsRefuseWithTheCodeOfTheRefusal(): void
    {
        $id = $this->recorded('FV-8');

        $call = $this->tool('foresi_set_line_allocation', ['document_id' => $id, 'line_key' => 'rate:21', 'kind' => 'whole', 'shares' => [['recipient' => Uuid::v7()->toRfc4122()]], 'idempotency_key' => 'bad-1']);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame('invalid_argument', $this->in($call, 'structuredContent', 'error', 'code'));
        self::assertSame(['parameter' => 'shares', 'reason' => 'unknown_contact'], $this->in($call, 'structuredContent', 'error', 'details'));

        $call = $this->tool('foresi_get_document_allocation', ['document_id' => Uuid::v7()->toRfc4122()]);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame('unknown_document', $this->in($call, 'structuredContent', 'error', 'code'));
    }

    public function testTwoDocumentsKeepTheirOwnMappingsAndAssignments(): void
    {
        $first = $this->recorded('FV-10');
        $second = $this->recorded('FV-11');

        $this->send('PUT', $this->line($first, 'rate:21'), ['kind' => 'whole', 'shares' => [['recipient' => $this->customerId]]]);
        $this->send('POST', '/api/v1/margin/documents/'.$first.'/assign', ['recipient' => $this->customerId]);
        $this->get('/api/v1/margin/documents/'.$second, secret: $this->writer);
        self::assertNull($this->at('data', 'document_allocation'), 'The other document is not assigned.');
        self::assertNull($this->at('data', 'lines', 0, 'allocation'));
        self::assertSame([null, 'unassigned'], [$this->at('data', 'lines', 0, 'shares', 0, 'recipient'), $this->at('data', 'lines', 0, 'shares', 0, 'source')]);

        $this->send('PUT', $this->line($second, 'rate:21'), ['kind' => 'percent', 'shares' => [['recipient' => 'own', 'percent' => '25'], ['recipient' => $this->customerId, 'percent' => '75']]]);
        $this->send('POST', '/api/v1/margin/documents/'.$second.'/assign', ['recipient' => 'own']);

        $this->get('/api/v1/margin/documents/'.$first, secret: $this->writer);
        self::assertSame(['whole', $this->customerId, $this->customerId], [$this->at('data', 'lines', 0, 'allocation', 'kind'), $this->at('data', 'document_allocation', 'recipient'), $this->at('data', 'lines', 0, 'shares', 0, 'recipient')]);
        self::assertSame([[$this->customerId, 'Example Customer a.s.', 100_000, 'line']], $this->shares());
        $this->get('/api/v1/margin/documents/'.$second, secret: $this->writer);
        self::assertSame(['percent', 'own'], [$this->at('data', 'lines', 0, 'allocation', 'kind'), $this->at('data', 'document_allocation', 'recipient')]);
        self::assertSame([['own', null, 25_000, 'line'], [$this->customerId, 'Example Customer a.s.', 75_000, 'line']], $this->shares());
        self::assertSame(2, $this->em()->getRepository(LineAllocation::class)->count([]));
        self::assertSame(2, $this->em()->getRepository(DocumentAllocation::class)->count([]));
    }

    public function testAMappingAndAnAssignmentAreChangedInPlace(): void
    {
        $id = $this->recorded('FV-12');
        $this->send('PUT', $this->line($id, 'rate:21'), ['kind' => 'whole', 'shares' => [['recipient' => 'own']]]);
        $this->send('POST', '/api/v1/margin/documents/'.$id.'/assign', ['recipient' => 'own']);

        $this->send('PUT', $this->line($id, 'rate:21'), ['kind' => 'whole', 'shares' => [['recipient' => $this->customerId]]]);
        self::assertSame($this->customerId, $this->at('data', 'lines', 0, 'allocation', 'shares', 0, 'recipient'));
        $this->send('POST', '/api/v1/margin/documents/'.$id.'/assign', ['recipient' => $this->customerId]);
        self::assertSame($this->customerId, $this->at('data', 'document_allocation', 'recipient'));

        $this->em()->clear();
        self::assertSame(1, $this->em()->getRepository(LineAllocation::class)->count([]));
        self::assertSame(1, $this->em()->getRepository(DocumentAllocation::class)->count([]));
        $line = $this->em()->getRepository(LineAllocation::class)->findOneBy([]);
        self::assertInstanceOf(LineAllocation::class, $line);
        self::assertSame($this->customerId, $line->allocation()->shares[0]->recipient->toString());
    }

    public function testAFixedSplitTakesTheRestAndAnAmountWithSpacesAroundIt(): void
    {
        $id = $this->recorded('FV-13');

        $this->send('PUT', $this->line($id, 'rate:21'), ['kind' => 'fixed', 'shares' => [['recipient' => 'own', 'amount' => ' 300.00 '], ['recipient' => $this->customerId, 'rest' => true]]]);

        self::assertResponseIsSuccessful();
        self::assertSame([['own', null, 30_000, 'line'], [$this->customerId, 'Example Customer a.s.', 70_000, 'line']], $this->shares());
        self::assertSame([true, false], [$this->at('data', 'lines', 0, 'allocation', 'shares', 1, 'rest'), $this->at('data', 'lines', 0, 'allocation', 'shares', 0, 'rest')]);
        self::assertSame(30_000, $this->at('data', 'lines', 0, 'allocation', 'shares', 0, 'amount', 'amount_minor'));
    }

    public function testAMappingOfALineThatIsGoneCanBeClearedButNotSet(): void
    {
        $id = $this->recorded('FV-15');
        $this->flushed(function () use ($id): void {
            $this->em()->persist(new LineAllocation(Uuid::v7()->toRfc4122(), $id, 'rate:12', new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::own())]), new \DateTimeImmutable(), 'user'));
        });

        $this->send('PUT', $this->line($id, 'rate:12'), ['kind' => 'whole', 'shares' => [['recipient' => 'own']]]);
        $this->assertProblem(404, 'unknown_line');
        self::assertSame(1, $this->em()->getRepository(LineAllocation::class)->count([]));

        $this->send('PUT', $this->line($id, 'rate:12'), ['kind' => null]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->em()->getRepository(LineAllocation::class)->count([]));
    }

    public function testAContactThatIsNoLongerThereHasNoNameAndTheSharesComeInTheOrderOfTheirIds(): void
    {
        $id = $this->recorded('FV-16');
        $gone = Uuid::v7()->toRfc4122();
        $this->flushed(function () use ($id, $gone): void {
            $this->em()->persist(new LineAllocation(Uuid::v7()->toRfc4122(), $id, 'rate:21', new Allocation(AllocationKind::Whole, [new AllocationShare(Recipient::client($gone))]), new \DateTimeImmutable(), 'user'));
            // Written after the share of the recording, with a lower id.
            $this->em()->persist(new CostShare('00000000-0000-7000-8000-000000000001', $id, 'rate:21', new \DateTimeImmutable('2026-11-01'), $gone, 1, CostShareSource::Line));
        });

        $this->get('/api/v1/margin/documents/'.$id, secret: $this->writer);

        self::assertSame([$gone, null], [$this->at('data', 'lines', 0, 'allocation', 'shares', 0, 'recipient'), $this->at('data', 'lines', 0, 'allocation', 'shares', 0, 'recipient_name')]);
        self::assertSame([[$gone, null, 1, 'line'], [null, null, 100_000, 'unassigned']], $this->shares());
    }

    private function line(string $documentId, string $key): string
    {
        return '/api/v1/margin/documents/'.$documentId.'/lines/'.$key.'/allocation';
    }

    /**
     * @return list<array<mixed>> recipient, name, amount and source of the shares of the first line of the last answer
     */
    private function shares(): array
    {
        $shares = $this->at('data', 'lines', 0, 'shares');
        self::assertIsArray($shares);

        return array_map(fn (int $index): array => $this->shareOf(0, $index), array_keys($shares));
    }

    /**
     * @return array<mixed>
     */
    private function shareOf(int $line, int $share = 0): array
    {
        return [
            $this->at('data', 'lines', $line, 'shares', $share, 'recipient'),
            $this->at('data', 'lines', $line, 'shares', $share, 'recipient_name'),
            $this->at('data', 'lines', $line, 'shares', $share, 'amount', 'amount_minor'),
            $this->at('data', 'lines', $line, 'shares', $share, 'source'),
        ];
    }

    private function recorded(string $number): string
    {
        $this->send('POST', '/api/v1/received-documents', [
            'kind' => 'tax_invoice',
            'supplier_id' => $this->customerId,
            'supplier_number' => $number,
            'issue_date' => '2026-11-02',
            'due_date' => '2026-11-16',
            'received_date' => '2026-11-03',
            'total' => '1210.00',
            'vat_recap' => ['21:1000.00:210.00'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body, ?string $secret = null): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode((object) $body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        if (null === $this->session) {
            $this->mcp($this->writer, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        }
        $result = $this->in($this->mcp($this->writer, 'tools/call', ['name' => $name, 'arguments' => $arguments]), 'result');
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    private function mcp(string $secret, string $method, array $params = []): array
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
        if (null !== $this->session) {
            $server['HTTP_MCP_SESSION_ID'] = $this->session;
        }
        $this->client->request('POST', '/mcp', server: $server, content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $this->session ??= $this->client->getResponse()->headers->get('Mcp-Session-Id');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private function in(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
