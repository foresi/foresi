<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Mail\Infrastructure;

use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Mail\Application\InboundAddresses;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Domain\InboundAddress;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Party\Domain\Party;
use Foresi\Tests\Instance\Support\InstanceZone;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Foresi\Tests\Support\Mail\LimitedReceive;
use Foresi\Tests\Support\Mail\MailKit;
use Foresi\Tests\Support\Mail\PartialAddressIndex;
use Foresi\Tests\Support\Mail\RecordingPurpose;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * A person's inbound addresses over REST and MCP: a new address answers its full
 * text once, the list never shows it, and a revoked address stays listed. Each
 * person sees and changes only their own.
 */
final class MailAddressesApiTest extends ApiTestCase
{
    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        PartialAddressIndex::apply($this->em()->getConnection());
        Clock::set(new MockClock('2026-11-25 10:00:00', 'UTC'));
        InstanceZone::set(self::getContainer());
        $this->writer = $this->issued([ApiScope::from('mail_addresses:write'), ApiScope::from('mail_addresses:read')]);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheTokenIsShownOnceAndNeverListed(): void
    {
        $this->send('POST', '/api/v1/mail/addresses', ['purpose' => RecordingPurpose::OWNED_KEY, 'label' => 'Receipts']);

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        $address = $this->at('data', 'address');
        self::assertIsString($address);
        self::assertMatchesRegularExpression('/^bank\+[a-z2-7]{26}@example\.com$/D', $address);
        self::assertSame([RecordingPurpose::OWNED_KEY, 'Receipts', "bank+\u{2026}@example.com", '2026-11-25T11:00:00+01:00', null, null], [$this->at('data', 'purpose'), $this->at('data', 'label'), $this->at('data', 'masked_address'), $this->at('data', 'created_at'), $this->at('data', 'revoked_at'), $this->at('data', 'last_received_at')]);
        $token = (string) new InboundAddresses('bank+{token}@example.com')->tokenIn($address);

        $this->get('/api/v1/mail/addresses', secret: $this->writer);
        self::assertResponseIsSuccessful();
        self::assertSame([['id' => $id, 'purpose' => RecordingPurpose::OWNED_KEY, 'label' => 'Receipts', 'masked_address' => "bank+\u{2026}@example.com", 'created_at' => '2026-11-25T11:00:00+01:00', 'revoked_at' => null, 'last_received_at' => null, 'limit_reached_today' => false, 'dropped_today' => 0]], $this->json()['data']);
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent());

        $listed = $this->tool('foresi_list_mail_addresses', []);
        self::assertStringNotContainsString($token, (string) json_encode($listed));
        self::assertStringNotContainsString($address, (string) json_encode($listed));

        $this->send('PATCH', '/api/v1/mail/addresses/'.$id, ['label' => 'Renamed']);
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent());
        self::assertArrayNotHasKey('address', (array) $this->at('data'));
    }

    public function testTheTokenIsNotInTheAuditTrailOrTheLog(): void
    {
        $log = self::getContainer()->getParameter('kernel.cache_dir').'/test.log';
        $before = is_file($log) ? (string) file_get_contents($log) : '';

        $this->send('POST', '/api/v1/mail/addresses', ['purpose' => RecordingPurpose::OWNED_KEY, 'label' => 'Receipts']);
        $address = $this->at('data', 'address');
        self::assertIsString($address);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        $this->send('POST', '/api/v1/mail/addresses/'.$id.'/replace');
        $replacement = $this->at('data', 'address');
        self::assertIsString($replacement);

        $audit = (string) json_encode($this->em()->getConnection()->fetchAllAssociative('SELECT * FROM audit_entry'));
        $logged = substr(is_file($log) ? (string) file_get_contents($log) : '', \strlen($before));
        foreach ([$address, $replacement] as $issued) {
            $token = (string) new InboundAddresses('bank+{token}@example.com')->tokenIn($issued);
            self::assertNotSame('', $token);
            foreach ([$token, hash('sha256', $token)] as $secret) {
                self::assertStringNotContainsString($secret, $audit);
                self::assertStringNotContainsString($secret, $logged);
            }
        }
        self::assertStringContainsString('Receipts', $audit, 'the audit trail knows the address was made');
    }

    public function testTheDroppedCountIsShownOnTheAddressAndLoggedOnceADay(): void
    {
        $this->send('POST', '/api/v1/mail/addresses', ['purpose' => RecordingPurpose::OWNED_KEY, 'label' => 'Receipts']);
        $address = $this->at('data', 'address');
        self::assertIsString($address);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        $token = (string) new InboundAddresses('bank+{token}@example.com')->tokenIn($address);
        $limited = LimitedReceive::of(static fn (string $id): mixed => self::getContainer()->get($id), 1, self::getContainer()->get(InstanceClock::class));
        $receive = static fn (string $messageId): ReceiveOutcome => $limited->receive->receive(MailKit::mail($address, $messageId), InboundChannel::Webhook);
        $row = function () use ($id): array {
            $this->get('/api/v1/mail/addresses', secret: $this->writer);
            self::assertResponseIsSuccessful();
            $rows = $this->json()['data'];
            self::assertIsArray($rows);
            $listed = $rows[0];
            self::assertIsArray($listed);
            self::assertSame($id, $listed['id']);

            return $listed;
        };

        self::assertSame(ReceiveOutcome::Stored, $receive('d1@mail.example.com'));
        self::assertSame([false, 0], [$row()['limit_reached_today'], $row()['dropped_today']]);
        foreach (['d2', 'd3', 'd4'] as $name) {
            self::assertSame(ReceiveOutcome::OverLimit, $receive($name.'@mail.example.com'));
        }
        self::assertSame([true, 3], [$row()['limit_reached_today'], $row()['dropped_today']]);
        self::assertSame(1, $limited->logLines());
        self::assertSame('warning', $limited->line(0)['level']);
        self::assertStringContainsString($id, $limited->line(0)['context']);
        self::assertStringContainsString('"limit":1', $limited->line(0)['context']);
        self::assertStringNotContainsString($token, $limited->line(0)['message'].$limited->line(0)['context']);

        // The MCP list says it too.
        $listed = $this->tool('foresi_list_mail_addresses', []);
        self::assertSame([true, 3], [$this->in($listed, 'structuredContent', 'data', 'addresses', 0, 'limit_reached_today'), $this->in($listed, 'structuredContent', 'data', 'addresses', 0, 'dropped_today')]);

        // The next instance day starts again: shown as 0, and the first drop of it is logged.
        Clock::set(new MockClock('2026-11-26 10:00:00', 'UTC'));
        self::assertSame([false, 0], [$row()['limit_reached_today'], $row()['dropped_today']]);
        self::assertSame(ReceiveOutcome::Stored, $receive('e1@mail.example.com'));
        self::assertSame(ReceiveOutcome::OverLimit, $receive('e2@mail.example.com'));
        self::assertSame(ReceiveOutcome::OverLimit, $receive('e3@mail.example.com'));
        self::assertSame([true, 2], [$row()['limit_reached_today'], $row()['dropped_today']]);
        self::assertSame(2, $limited->logLines());
    }

    public function testIssuedAddressesAreNeverCached(): void
    {
        $this->send('POST', '/api/v1/mail/addresses', ['purpose' => RecordingPurpose::OWNED_KEY, 'label' => 'Receipts']);
        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        $this->send('POST', '/api/v1/mail/addresses/'.$id.'/replace');
        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertNotSame($id, $this->at('data', 'id'));
        self::assertSame('Receipts', $this->at('data', 'label'));
    }

    public function testRevokesAnAddressAndItStaysListed(): void
    {
        $this->send('POST', '/api/v1/mail/addresses', ['purpose' => RecordingPurpose::OWNED_KEY, 'label' => 'Receipts']);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        $this->send('DELETE', '/api/v1/mail/addresses/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSame('2026-11-25T11:00:00+01:00', $this->at('data', 'revoked_at'));
        $this->send('DELETE', '/api/v1/mail/addresses/'.$id);
        $this->assertProblem(409, 'address_revoked');

        $this->get('/api/v1/mail/addresses', secret: $this->writer);
        self::assertSame('2026-11-25T11:00:00+01:00', $this->at('data', 0, 'revoked_at'));
    }

    public function testTheRefusalsAreAnswered(): void
    {
        $this->send('POST', '/api/v1/mail/addresses', ['purpose' => 'bank_feed', 'label' => 'Mine']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'purpose', 'reason' => 'purpose_has_no_owners']);
        $this->send('POST', '/api/v1/mail/addresses', ['purpose' => 'nope', 'label' => 'Mine']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'purpose', 'reason' => 'purpose_unknown']);
        $this->send('POST', '/api/v1/mail/addresses', ['purpose' => RecordingPurpose::OWNED_KEY, 'label' => ' ']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'label', 'reason' => 'label_required']);
        $this->send('POST', '/api/v1/mail/addresses', ['purpose' => RecordingPurpose::OWNED_KEY, 'label' => str_repeat('x', 81)]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'label', 'reason' => 'label_too_long']);

        $this->send('POST', '/api/v1/mail/addresses', ['purpose' => RecordingPurpose::OWNED_KEY, 'label' => 'Receipts']);
        self::assertResponseStatusCodeSame(201);
        $this->send('POST', '/api/v1/mail/addresses', ['purpose' => RecordingPurpose::OWNED_KEY, 'label' => 'Second']);
        $this->assertProblem(409, 'address_exists');
    }

    public function testAnotherPersonsAddressIsNotFound(): void
    {
        $other = $this->flushed(function (): InboundAddress {
            $someone = $this->fixtures->user('someone@example.com');
            $this->fixtures->join($someone, Role::Member);
            $address = new InboundAddress(RecordingPurpose::OWNED_KEY, $someone->id(), 'Theirs', hash('sha256', 'theirs'), null, new \DateTimeImmutable('2026-11-24 10:00 UTC'));
            $this->em()->persist($address);

            return $address;
        });

        $this->send('PATCH', '/api/v1/mail/addresses/'.$other->id(), ['label' => 'Mine now']);
        $this->assertProblem(404, 'not_found');
        $this->send('DELETE', '/api/v1/mail/addresses/'.$other->id());
        $this->assertProblem(404, 'not_found');
        $this->send('POST', '/api/v1/mail/addresses/'.$other->id().'/replace');
        $this->assertProblem(404, 'not_found');
        $this->get('/api/v1/mail/addresses', secret: $this->writer);
        self::assertSame([], $this->json()['data']);
    }

    public function testTheListFiltersByPurpose(): void
    {
        $this->send('POST', '/api/v1/mail/addresses', ['purpose' => RecordingPurpose::OWNED_KEY, 'label' => 'Receipts']);

        $this->get('/api/v1/mail/addresses', ['purpose' => RecordingPurpose::OWNED_KEY], $this->writer);
        self::assertCount(1, (array) $this->json()['data']);
        $this->get('/api/v1/mail/addresses', ['purpose' => 'other'], $this->writer);
        self::assertSame([], $this->json()['data']);
        $listed = $this->tool('foresi_list_mail_addresses', ['purpose' => 'other']);
        self::assertSame([], $this->in($listed, 'structuredContent', 'data', 'addresses'));
    }

    public function testTheSameOverMcp(): void
    {
        $created = $this->tool('foresi_create_mail_address', ['purpose' => RecordingPurpose::OWNED_KEY, 'label' => 'Receipts']);
        self::assertFalse($created['isError'] ?? false);
        $id = $this->in($created, 'structuredContent', 'data', 'id');
        self::assertIsString($id);
        self::assertIsString($this->in($created, 'structuredContent', 'data', 'address'));

        $renamed = $this->tool('foresi_rename_mail_address', ['address_id' => $id, 'label' => 'Tablet']);
        self::assertSame('Tablet', $this->in($renamed, 'structuredContent', 'data', 'label'));

        $replaced = $this->tool('foresi_replace_mail_address', ['address_id' => $id]);
        $newId = $this->in($replaced, 'structuredContent', 'data', 'id');
        self::assertIsString($newId);
        self::assertSame('Tablet', $this->in($replaced, 'structuredContent', 'data', 'label'));
        self::assertIsString($this->in($replaced, 'structuredContent', 'data', 'address'));

        $listed = $this->tool('foresi_list_mail_addresses', []);
        self::assertFalse($listed['isError'] ?? false);
        $addresses = $this->in($listed, 'structuredContent', 'data', 'addresses');
        self::assertIsArray($addresses);
        self::assertSame([$id, $newId], array_column($addresses, 'id'));

        $revoked = $this->tool('foresi_revoke_mail_address', ['address_id' => $newId]);
        self::assertSame('2026-11-25T11:00:00+01:00', $this->in($revoked, 'structuredContent', 'data', 'revoked_at'));

        $refused = $this->tool('foresi_create_mail_address', ['purpose' => 'bank_feed', 'label' => 'Mine']);
        self::assertTrue($refused['isError'] ?? false);
    }

    public function testATokenWithoutTheWriteScopeCannotCreate(): void
    {
        $reader = $this->issued([ApiScope::from('mail_addresses:read')]);

        $this->get('/api/v1/mail/addresses', secret: $reader);
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/v1/mail/addresses', ['purpose' => RecordingPurpose::OWNED_KEY, 'label' => 'Receipts'], $reader);
        $this->assertProblem(403, 'insufficient_scope');
        self::assertSame([], $this->em()->getRepository(InboundAddress::class)->findAll());

        $this->get('/api/v1/mail/addresses', secret: $this->issued([ApiScope::from('contacts:read')]));
        $this->assertProblem(403, 'insufficient_scope');
    }

    public function testAGuestIsRefused(): void
    {
        $membership = $this->em()->getRepository(Membership::class)->findOneBy(['user' => $this->user->id()]);
        self::assertInstanceOf(Membership::class, $membership);
        $membership->changeRole(Role::Guest, $this->em()->find(Party::class, $this->customerId));
        $this->em()->flush();

        $this->send('POST', '/api/v1/mail/addresses', ['purpose' => RecordingPurpose::OWNED_KEY, 'label' => 'Receipts']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->get('/api/v1/mail/addresses', secret: $this->writer);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        self::assertSame([], $this->em()->getRepository(InboundAddress::class)->findAll());
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null, ?string $secret = null): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(8))], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        if (null === $this->session) {
            $this->mcp('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        }
        $arguments += str_starts_with($name, 'foresi_list_') ? [] : ['idempotency_key' => bin2hex(random_bytes(8))];
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
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->writer, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
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
