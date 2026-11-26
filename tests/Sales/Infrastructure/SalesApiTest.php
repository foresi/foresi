<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Infrastructure;

use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Application\ApiTokenRefused;
use Foresi\Identity\Application\ManageApiTokens;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Application\LinkContactPerson;
use Foresi\Party\Domain\Party;
use Foresi\Party\Domain\PartyKind;
use Foresi\Sales\Infrastructure\Mcp\GetDealTool;
use Foresi\Sales\Infrastructure\Mcp\ListDealStagesTool;
use Foresi\Sales\Infrastructure\Mcp\SearchDealsTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Operation\Operation;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * Sales over REST and MCP: every write declared once with #[Operation], the
 * reads under sales:read, an idempotent win, and a guest refused on both
 * surfaces.
 */
final class SalesApiTest extends ApiTestCase
{
    private string $writer;
    private ?string $session = null;
    private string $petra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('sales:write')]);
        $this->petra = $this->flushed(function (): string {
            $client = $this->em()->find(Party::class, $this->customerId);
            self::assertInstanceOf(Party::class, $client);
            $person = new Party(PartyKind::Person, 'Petra Example', null, null, '');
            $this->em()->persist($person);
            $this->em()->flush();
            self::getContainer()->get(LinkContactPerson::class)->link($client, $person, '', false);

            return $person->id();
        });
    }

    public function testStagesAndLostReasonsAreKeptOverRest(): void
    {
        $inquiry = $this->created('/api/v1/sales/stages', ['name' => 'Inquiry', 'key' => 'inquiry']);
        $proposal = $this->created('/api/v1/sales/stages', ['name' => 'Proposal']);
        self::assertSame([null, 'Proposal', 1, false], [$this->at('data', 'key'), $this->at('data', 'name'), $this->at('data', 'position'), $this->at('data', 'archived')]);

        $this->send('PATCH', '/api/v1/sales/stages/'.$proposal, ['name' => 'Offer']);
        self::assertSame('Offer', $this->at('data', 'name'));
        $this->send('POST', '/api/v1/sales/stages/reorder', ['stage_ids' => [$proposal, $inquiry]]);
        self::assertResponseIsSuccessful();
        $items = $this->at('data', 'items');
        self::assertIsArray($items);
        self::assertSame([$proposal, $inquiry], array_column($items, 'id'));
        $this->send('POST', '/api/v1/sales/stages/'.$inquiry.'/archive');
        self::assertTrue($this->at('data', 'archived'));
        $this->get('/api/v1/sales/stages', [], $this->writer);
        self::assertSame([$proposal], $this->ids());
        $this->get('/api/v1/sales/stages', ['include_archived' => 'true'], $this->writer);
        self::assertSame([$proposal, $inquiry], $this->ids());

        $price = $this->created('/api/v1/sales/lost-reasons', ['name' => 'Price', 'key' => 'price']);
        $this->send('PATCH', '/api/v1/sales/lost-reasons/'.$price, ['name' => 'Too expensive']);
        self::assertSame(['price', 'Too expensive'], [$this->at('data', 'key'), $this->at('data', 'name')]);
        $this->send('POST', '/api/v1/sales/lost-reasons/'.$price.'/archive');
        self::assertTrue($this->at('data', 'archived'));
        $this->get('/api/v1/sales/lost-reasons', [], $this->writer);
        self::assertSame([], $this->ids());
        $this->get('/api/v1/sales/lost-reasons', ['include_archived' => '1'], $this->writer);
        self::assertSame([$price], $this->ids());
    }

    public function testADealGoesThroughItsLifeOverRest(): void
    {
        $inquiry = $this->created('/api/v1/sales/stages', ['name' => 'Inquiry']);
        $proposal = $this->created('/api/v1/sales/stages', ['name' => 'Proposal']);
        $price = $this->created('/api/v1/sales/lost-reasons', ['name' => 'Price']);

        $id = $this->created('/api/v1/sales/deals', ['title' => 'Roof audit', 'client_party_id' => $this->customerId, 'contact_person_ids' => [$this->petra]]);
        self::assertResponseHeaderSame('Location', '/api/v1/sales/deals/'.$id);
        self::assertSame(['open', $inquiry, 'Inquiry'], [$this->at('data', 'status'), $this->at('data', 'stage', 'id'), $this->at('data', 'stage', 'name')]);
        self::assertSame(['id' => $this->customerId, 'name' => 'Example Customer a.s.'], $this->at('data', 'client'));
        self::assertSame([['id' => $this->petra, 'name' => 'Petra Example']], $this->at('data', 'contacts'));
        self::assertSame($this->user->id(), $this->at('data', 'owner_user_id'));
        self::assertSame(['one_off' => [], 'monthly' => [], 'approximate' => false, 'hourly' => []], $this->at('data', 'value'));

        $this->send('PATCH', '/api/v1/sales/deals/'.$id, ['title' => 'Roof audit 2026', 'description' => 'Two buildings.']);
        self::assertSame(['Roof audit 2026', 'Two buildings.'], [$this->at('data', 'title'), $this->at('data', 'description')]);

        $this->send('POST', '/api/v1/sales/deals/'.$id.'/lines', ['name' => 'Audit', 'unit' => 'piece', 'quantity' => '2', 'unit_price' => '12000.00', 'currency' => 'CZK']);
        self::assertResponseStatusCodeSame(201);
        $audit = $this->at('data', 'lines', 0, 'id');
        self::assertIsString($audit);
        self::assertSame(['2.000', ['amount_minor' => 1200000, 'currency' => 'CZK'], 'one_off'], [$this->at('data', 'lines', 0, 'quantity'), $this->at('data', 'lines', 0, 'unit_price'), $this->at('data', 'lines', 0, 'billing_kind')]);
        $this->send('POST', '/api/v1/sales/deals/'.$id.'/lines', ['name' => 'Maintenance', 'unit' => 'month', 'unit_price' => '5000.00', 'currency' => 'CZK', 'billing_kind' => 'recurring', 'recurrence_unit' => 'month', 'interval' => 1]);
        $maintenance = $this->at('data', 'lines', 1, 'id');
        self::assertIsString($maintenance);
        self::assertSame(['one_off' => [['amount_minor' => 2400000, 'currency' => 'CZK']], 'monthly' => [['amount_minor' => 500000, 'currency' => 'CZK']], 'approximate' => false, 'hourly' => []], $this->at('data', 'value'));

        $this->send('PATCH', '/api/v1/sales/deals/'.$id.'/lines/'.$maintenance, ['recurrence_unit' => 'week']);
        self::assertTrue($this->at('data', 'value', 'approximate'));
        $this->send('POST', '/api/v1/sales/deals/'.$id.'/lines/reorder', ['line_ids' => [$maintenance, $audit]]);
        $lines = $this->at('data', 'lines');
        self::assertIsArray($lines);
        self::assertSame([$maintenance, $audit], array_column($lines, 'id'));
        $this->send('DELETE', '/api/v1/sales/deals/'.$id.'/lines/'.$maintenance);
        $lines = $this->at('data', 'lines');
        self::assertIsArray($lines);
        self::assertSame([$audit], array_column($lines, 'id'));

        $this->send('POST', '/api/v1/sales/deals/'.$id.'/stage', ['stage_id' => $proposal]);
        self::assertSame($proposal, $this->at('data', 'stage', 'id'));
        $this->send('POST', '/api/v1/sales/deals/'.$id.'/lose', ['lost_reason_id' => $price, 'note' => 'Went with a cheaper offer.']);
        self::assertSame(['lost', $price, 'Went with a cheaper offer.'], [$this->at('data', 'status'), $this->at('data', 'lost_reason', 'id'), $this->at('data', 'lost_note')]);
        $this->send('POST', '/api/v1/sales/deals/'.$id.'/reopen');
        self::assertSame(['open', null, null], [$this->at('data', 'status'), $this->at('data', 'lost_reason'), $this->at('data', 'lost_note')]);
        $this->send('POST', '/api/v1/sales/deals/'.$id.'/win');
        self::assertSame('won', $this->at('data', 'status'));
        self::assertIsString($this->at('data', 'won_at'));

        $followUp = $this->created('/api/v1/sales/deals/'.$id.'/follow-ups', ['title' => 'Roof repair', 'line_ids' => [$audit]]);
        self::assertSame([['id' => $this->petra, 'name' => 'Petra Example']], $this->at('data', 'contacts'));
        self::assertSame(['Audit'], array_column($this->listAt('data', 'lines'), 'name'));
        self::assertSame([['type' => 'follows_from', 'direction' => 'outgoing', 'deal' => ['id' => $id, 'title' => 'Roof audit 2026', 'status' => 'won']]], array_map(static fn (array $link): array => array_diff_key($link, ['id' => true]), $this->listAt('data', 'links')));

        $third = $this->created('/api/v1/sales/deals', ['title' => 'Facade', 'client_party_id' => $this->customerId]);
        $this->send('POST', '/api/v1/sales/deals/'.$third.'/links', ['to_deal_id' => $id]);
        self::assertResponseStatusCodeSame(201);
        $link = $this->at('data', 'links', 0, 'id');
        self::assertIsString($link);
        $this->send('POST', '/api/v1/sales/deals/'.$third.'/links', ['to_deal_id' => $id]);
        $this->assertProblem(409, 'link_duplicate');
        $this->send('DELETE', '/api/v1/sales/deals/'.$third.'/links/'.$link);
        self::assertSame([], $this->at('data', 'links'));

        $this->send('POST', '/api/v1/sales/deals/'.$third.'/trash');
        self::assertTrue($this->at('data', 'trashed'));
        $this->get('/api/v1/sales/deals', [], $this->writer);
        self::assertSame([$followUp, $id], $this->ids());
        $this->send('POST', '/api/v1/sales/deals/'.$third.'/restore');
        self::assertFalse($this->at('data', 'trashed'));

        $this->get('/api/v1/sales/deals', ['status' => 'won'], $this->writer);
        self::assertSame([$id], $this->ids());
        self::assertSame(['Roof audit 2026', 'won', $proposal], [$this->at('data', 0, 'title'), $this->at('data', 0, 'status'), $this->at('data', 0, 'stage', 'id')]);
        $this->get('/api/v1/sales/deals', ['stage_id' => $inquiry, 'client_party_id' => $this->customerId, 'owner_user_id' => $this->user->id()], $this->writer);
        self::assertSame([$third, $followUp], $this->ids());
        $this->get('/api/v1/sales/deals', ['q' => 'ROOF'], $this->writer);
        self::assertSame([$followUp, $id], $this->ids());
        $this->get('/api/v1/sales/deals', ['status' => 'pending'], $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'status', 'reason' => 'status_unknown']);
        $this->get('/api/v1/sales/deals/'.$id, [], $this->writer);
        self::assertSame([['type' => 'follows_from', 'direction' => 'incoming', 'deal' => ['id' => $followUp, 'title' => 'Roof repair', 'status' => 'open']]], array_map(static fn (array $link): array => array_diff_key($link, ['id' => true]), $this->listAt('data', 'links')));
        $this->get('/api/v1/sales/deals/0192f000-0000-7000-8000-000000000000', [], $this->writer);
        $this->assertProblem(404, 'not_found');
    }

    public function testTheSameWinTwiceWinsOnce(): void
    {
        $this->created('/api/v1/sales/stages', ['name' => 'Inquiry']);
        $id = $this->created('/api/v1/sales/deals', ['title' => 'Roof audit', 'client_party_id' => $this->customerId]);

        $this->send('POST', '/api/v1/sales/deals/'.$id.'/win', key: 'win-roof-audit');
        self::assertResponseIsSuccessful();
        $first = $this->json();
        $this->send('POST', '/api/v1/sales/deals/'.$id.'/win', key: 'win-roof-audit');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Idempotent-Replayed', 'true');
        self::assertSame($first, $this->json());

        $won = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ?', ['sales.deal.won']);
        self::assertTrue(1 === $won || '1' === $won, 'One deal is won once.');
        // Another key is another win, refused now the deal is won.
        $this->send('POST', '/api/v1/sales/deals/'.$id.'/win', key: 'win-roof-audit-again');
        $this->assertProblem(409, 'deal_not_open');
    }

    public function testTheToolsReadAsRestDoes(): void
    {
        $stage = $this->tool('foresi_create_deal_stage', ['name' => 'Inquiry', 'idempotency_key' => 'stage-inquiry']);
        self::assertFalse($stage['isError'] ?? false);
        $this->tool('foresi_create_lost_reason', ['name' => 'Price', 'idempotency_key' => 'reason-price']);
        $created = $this->tool('foresi_create_deal', ['title' => 'Roof audit', 'client_party_id' => $this->customerId, 'idempotency_key' => 'create-roof-audit']);
        self::assertFalse($created['isError'] ?? false);
        $id = $this->in($created, 'structuredContent', 'data', 'id');
        self::assertIsString($id);
        $this->tool('foresi_create_deal', ['title' => 'Facade', 'client_party_id' => $this->customerId, 'idempotency_key' => 'create-facade']);

        $found = $this->tool('foresi_search_deals', ['query' => 'roof', 'status' => 'open', 'client_party_id' => $this->customerId]);
        $items = $this->in($found, 'structuredContent', 'data', 'items');
        self::assertIsArray($items);
        self::assertSame([$id], array_column($items, 'id'));
        $one = $this->tool('foresi_get_deal', ['id' => $id]);
        $this->get('/api/v1/sales/deals/'.$id, [], $this->writer);
        self::assertSame($this->json()['data'], $this->in($one, 'structuredContent', 'data'));
        $stages = $this->tool('foresi_list_deal_stages', []);
        self::assertSame(['Inquiry'], array_column($this->arrayIn($stages, 'structuredContent', 'data', 'stages'), 'name'));
        self::assertSame(['Price'], array_column($this->arrayIn($stages, 'structuredContent', 'data', 'lost_reasons'), 'name'));

        // An hourly line without hours, over MCP: the rate is in the value, apart from the totals.
        $line = $this->tool('foresi_add_deal_line', ['deal_id' => $id, 'name' => 'Changes', 'unit' => 'h', 'unit_price' => '1200.00', 'currency' => 'CZK', 'billing_kind' => 'time_based', 'idempotency_key' => 'line-changes']);
        self::assertFalse($line['isError'] ?? false);
        $hourly = $this->tool('foresi_get_deal', ['id' => $id]);
        self::assertSame([['rate' => ['amount_minor' => 120000, 'currency' => 'CZK'], 'expected_hours' => null, 'expected_amount' => null]], $this->in($hourly, 'structuredContent', 'data', 'value', 'hourly'));
        self::assertSame([], $this->in($hourly, 'structuredContent', 'data', 'value', 'monthly'));
        $this->get('/api/v1/sales/deals/'.$id, [], $this->writer);
        self::assertSame($this->json()['data'], $this->in($hourly, 'structuredContent', 'data'));

        $refused = $this->tool('foresi_get_deal', ['id' => '0192f000-0000-7000-8000-000000000000']);
        self::assertTrue($refused['isError'] ?? false);
    }

    public function testScopesDecideWhatATokenDoes(): void
    {
        // The default token reads everything and writes nothing.
        $this->get('/api/v1/sales/deals', [], $this->secret);
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/v1/sales/stages', ['name' => 'Inquiry'], $this->secret);
        $this->assertProblem(403, 'insufficient_scope');
        $reader = $this->issued([ApiScope::from('sales:read')]);
        $this->send('POST', '/api/v1/sales/deals', ['title' => 'Roof audit', 'client_party_id' => $this->customerId], $reader);
        $this->assertProblem(403, 'insufficient_scope');

        $contacts = $this->issued([ApiScope::from('contacts:read')]);
        foreach (['/api/v1/sales/deals', '/api/v1/sales/stages', '/api/v1/sales/lost-reasons'] as $path) {
            $this->get($path, [], $contacts);
            $this->assertProblem(403, 'insufficient_scope');
        }
    }

    public function testAGuestIsRefused(): void
    {
        // A guest holds no personal token (ADR 0041), and the tools of Sales are hidden from a guest's OAuth connection.
        $guest = $this->fixtures->user('guest@example.com');
        $this->fixtures->join($guest, Role::Guest);
        try {
            self::getContainer()->get(ManageApiTokens::class)->issueAsOperator($guest->id(), 'Guest', [ApiScope::from('sales:write')]);
            self::fail('A guest was given a token.');
        } catch (ApiTokenRefused) {
        }
        foreach ([SearchDealsTool::class, GetDealTool::class, ListDealStagesTool::class] as $tool) {
            $read = self::getContainer()->get($tool);
            self::assertInstanceOf(StaffOnlyTool::class, $read);
            self::assertTrue($read->staffOnly());
        }
        $inputs = glob(\dirname(__DIR__, 3).'/src/Sales/Application/Api/*Input.php');
        self::assertIsArray($inputs);
        self::assertCount(27, $inputs);
        foreach ($inputs as $file) {
            $input = 'Foresi\\Sales\\Application\\Api\\'.basename($file, '.php');
            self::assertTrue(class_exists($input));
            self::assertTrue(new \ReflectionClass($input)->getAttributes(Operation::class)[0]->newInstance()->staffOnly, $input);
        }
    }

    /**
     * Sends a write that answers 201, and answers the id of what it made.
     *
     * @param array<string, mixed> $body
     */
    private function created(string $path, array $body): string
    {
        $this->send('POST', $path, $body);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null, ?string $secret = null, ?string $key = null): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key ?? bin2hex(random_bytes(8))], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array<mixed>>
     */
    private function listAt(string|int ...$keys): array
    {
        $value = $this->at(...$keys);
        self::assertIsArray($value);

        return array_values(array_map(static fn (mixed $item): array => \is_array($item) ? $item : throw new \LogicException('Not a list of objects.'), $value));
    }

    /**
     * @return list<array<mixed>>
     */
    private function arrayIn(mixed $value, string|int ...$path): array
    {
        $found = $this->in($value, ...$path);
        self::assertIsArray($found);

        return array_values(array_map(static fn (mixed $item): array => \is_array($item) ? $item : throw new \LogicException('Not a list of objects.'), $found));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        $result = $this->in($this->mcp('tools/call', ['name' => $name, 'arguments' => $arguments]), 'result');
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    private function mcp(string $method, array $params = []): array
    {
        if (null === $this->session && 'initialize' !== $method) {
            $this->mcp('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        }
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->writer, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
        if (null !== $this->session) {
            $server['HTTP_MCP_SESSION_ID'] = $this->session;
        }
        $this->client->request('POST', '/mcp', server: $server, content: json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => [] === $params ? new \stdClass() : $params], \JSON_THROW_ON_ERROR));
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
