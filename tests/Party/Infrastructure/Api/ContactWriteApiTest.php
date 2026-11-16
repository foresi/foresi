<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Party\Infrastructure\Api;

use App\Identity\Domain\ApiScope;
use App\Party\Domain\Party;
use App\Tests\Shared\Infrastructure\Api\ApiTestCase;

/**
 * The contact writes of ADR 0032 over REST and MCP: the same input, the same
 * rules, the same refusals and the contact detail as the answer on both.
 */
final class ContactWriteApiTest extends ApiTestCase
{
    private const array COMPANY = [
        'kind' => 'company',
        'display_name' => 'Alpha Example s.r.o.',
        'registration_number' => '12345679',
        'email' => 'alpha@example.com',
        'phone' => '+420 111 111 111',
        'roles' => ['customer'],
        'street' => 'Example Street 1',
        'city' => 'Example City',
        'postal_code' => '100 00',
        'country_code' => 'CZ',
    ];

    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('contacts:write')]);
    }

    public function testCreateAnswers201WithTheDetailAndItsLocation(): void
    {
        $this->send('POST', '/api/v1/contacts', self::COMPANY);

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertResponseHeaderSame('Location', '/api/v1/contacts/'.$id);
        $created = $this->json()['data'];
        self::assertSame('company', $this->at('data', 'kind'));
        self::assertSame('CZ', $this->at('data', 'registration_country'));
        self::assertSame('12345679', $this->at('data', 'registration_number'));
        self::assertSame('alpha@example.com', $this->at('data', 'email'));
        self::assertSame(['street' => 'Example Street 1', 'city' => 'Example City', 'postal_code' => '100 00', 'country_code' => 'CZ'], $this->at('data', 'billing_address'));

        // The answer is the detail the read endpoint gives.
        $this->get('/api/v1/contacts/'.$id, secret: $this->writer);
        self::assertSame($this->json()['data'], $created);
    }

    public function testCreateOverMcpAnswersWhatRestAnswers(): void
    {
        $call = $this->tool('foresi_create_contact', self::COMPANY);

        self::assertFalse($call['isError'] ?? false);
        $created = $this->in($call, 'structuredContent', 'data');
        self::assertIsArray($created);
        $id = $created['id'] ?? null;
        self::assertIsString($id);
        $this->get('/api/v1/contacts/'.$id, secret: $this->writer);
        self::assertSame($this->json()['data'], $created);
        $text = $this->in($call, 'content', 0, 'text');
        self::assertIsString($text);
        self::assertStringContainsString('Created the contact Alpha Example s.r.o..', $text);
    }

    public function testADuplicateIsRefusedWithTheIdsItMatchedOnBothSurfaces(): void
    {
        $this->send('POST', '/api/v1/contacts', self::COMPANY);
        $id = $this->at('data', 'id');

        $this->send('POST', '/api/v1/contacts', ['display_name' => 'Alpha Again'] + self::COMPANY);
        $this->assertProblem(409, 'duplicate', ['existing_ids' => [$id]]);

        $call = $this->tool('foresi_create_contact', ['display_name' => 'Alpha Again'] + self::COMPANY);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame('duplicate', $this->in($call, 'structuredContent', 'error', 'code'));
        self::assertSame([$id], $this->in($call, 'structuredContent', 'error', 'details', 'existing_ids'));
        self::assertCount(1, $this->contactsNamed('Alpha'));
    }

    public function testTheRulesOfTheScreensRefuseAtTheField(): void
    {
        $this->send('POST', '/api/v1/contacts', ['display_name' => ''] + self::COMPANY);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'display_name', 'reason' => 'required']);

        $this->send('POST', '/api/v1/contacts', ['registration_number' => ''] + self::COMPANY);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'registration_number', 'reason' => 'company_identifier_required']);

        $this->send('POST', '/api/v1/contacts', ['kind' => 'robot'] + self::COMPANY);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'kind']);

        $this->send('POST', '/api/v1/contacts', ['registration_country' => 'DE', 'registration_number' => str_repeat('1', 65)] + self::COMPANY);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'registration_number']);

        $call = $this->tool('foresi_create_contact', ['email' => 'not an e-mail'] + self::COMPANY);
        self::assertTrue($call['isError'] ?? false);
        self::assertSame('invalid_argument', $this->in($call, 'structuredContent', 'error', 'code'));
        self::assertSame('email', $this->in($call, 'structuredContent', 'error', 'details', 'parameter'));
        self::assertSame([], $this->contactsNamed('Alpha'));
    }

    public function testAnUpdateChangesWhatIsSentAndClearsWhatIsNull(): void
    {
        $id = $this->createdCompany();

        $this->send('PATCH', '/api/v1/contacts/'.$id, ['phone' => '+420 222 222 222', 'city' => 'Other City']);
        self::assertResponseIsSuccessful();
        self::assertSame('+420 222 222 222', $this->at('data', 'phone'));
        self::assertSame('Other City', $this->at('data', 'billing_address', 'city'));
        // What was left out is kept.
        self::assertSame('alpha@example.com', $this->at('data', 'email'));
        self::assertSame('12345679', $this->at('data', 'registration_number'));
        self::assertSame(['customer'], $this->at('data', 'roles'));

        $call = $this->tool('foresi_update_contact', ['contact_id' => $id, 'email' => null, 'roles' => ['supplier']]);
        self::assertFalse($call['isError'] ?? false);
        self::assertNull($this->in($call, 'structuredContent', 'data', 'email'));
        self::assertSame(['supplier'], $this->in($call, 'structuredContent', 'data', 'roles'));
        self::assertSame('+420 222 222 222', $this->in($call, 'structuredContent', 'data', 'phone'));
    }

    public function testAnUpdateRefusesTheIdentifiersOfAnotherContactButNotItsOwn(): void
    {
        $id = $this->createdCompany();
        $this->send('POST', '/api/v1/contacts', ['display_name' => 'Beta Example s.r.o.', 'registration_number' => '12345687'] + self::COMPANY);
        $other = $this->at('data', 'id');

        $this->send('PATCH', '/api/v1/contacts/'.$id, ['display_name' => 'Alpha Renamed', 'registration_number' => '1234 5679']);
        self::assertResponseIsSuccessful();
        self::assertSame('Alpha Renamed', $this->at('data', 'name'));

        $this->send('PATCH', '/api/v1/contacts/'.$id, ['registration_number' => '12345687']);
        $this->assertProblem(409, 'duplicate', ['existing_ids' => [$other]]);

        $this->send('PATCH', '/api/v1/contacts/'.$id, ['registration_number' => null]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'registration_number', 'reason' => 'company_identifier_required']);
    }

    public function testArchiveAndRestore(): void
    {
        $id = $this->createdCompany();

        $this->send('POST', '/api/v1/contacts/'.$id.'/archive');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->at('data', 'archived'));

        $call = $this->tool('foresi_restore_contact', ['contact_id' => $id]);
        self::assertFalse($this->in($call, 'structuredContent', 'data', 'archived'));

        $this->send('POST', '/api/v1/contacts/01963c5e-0000-7000-8000-000000000001/archive');
        $this->assertProblem(404, 'not_found');
    }

    public function testAContactPersonIsLinkedAndUnlinked(): void
    {
        $company = $this->createdCompany();
        $this->send('POST', '/api/v1/contacts', ['kind' => 'person', 'display_name' => 'Petra Example', 'registration_number' => ''] + self::COMPANY);
        $person = $this->at('data', 'id');
        self::assertIsString($person);

        $this->send('POST', '/api/v1/contacts/'.$company.'/contact-persons', ['person_id' => $person, 'position' => 'Accountant', 'primary' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $person, 'name' => 'Petra Example'], $this->at('data', 'contact_persons', 0, 'person'));
        self::assertSame('Accountant', $this->at('data', 'contact_persons', 0, 'position'));
        self::assertTrue($this->at('data', 'contact_persons', 0, 'primary'));

        $call = $this->tool('foresi_link_contact_person', ['contact_id' => $company, 'person_id' => $person]);
        self::assertSame('already_linked', $this->in($call, 'structuredContent', 'error', 'code'));

        // A company is no contact person.
        $this->send('POST', '/api/v1/contacts/'.$person.'/contact-persons', ['person_id' => $company]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'person_id', 'reason' => 'person_invalid']);

        $this->send('DELETE', '/api/v1/contacts/'.$company.'/contact-persons/'.$person);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->at('data', 'contact_persons'));
        $this->send('DELETE', '/api/v1/contacts/'.$company.'/contact-persons/'.$person);
        $this->assertProblem(404, 'not_linked');
    }

    public function testARetriedCreateWithAKeyCreatesOnce(): void
    {
        $this->send('POST', '/api/v1/contacts', self::COMPANY, idempotencyKey: 'create-alpha');
        self::assertResponseStatusCodeSame(201);
        $first = $this->json();

        $this->send('POST', '/api/v1/contacts', self::COMPANY, idempotencyKey: 'create-alpha');
        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('Idempotent-Replayed', 'true');
        self::assertSame($first, $this->json());

        $call = $this->tool('foresi_create_contact', self::COMPANY + ['idempotency_key' => 'create-alpha-mcp']);
        $again = $this->tool('foresi_create_contact', self::COMPANY + ['idempotency_key' => 'create-alpha-mcp']);
        // The MCP key is its own: the first call is refused as a duplicate, and the retry replays that refusal.
        self::assertSame('duplicate', $this->in($call, 'structuredContent', 'error', 'code'));
        self::assertSame('duplicate', $this->in($again, 'structuredContent', 'error', 'code'));
        self::assertCount(1, $this->contactsNamed('Alpha'));
    }

    public function testAReadTokenCannotWrite(): void
    {
        // A guest holds no valid token at all (EventApiControllerTest); the use cases refuse a guest on the screens too.
        $this->send('POST', '/api/v1/contacts', self::COMPANY, $this->secret);
        $this->assertProblem(403, 'insufficient_scope');
        self::assertSame([], $this->contactsNamed('Alpha'));
    }

    private function createdCompany(): string
    {
        $this->send('POST', '/api/v1/contacts', self::COMPANY);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<Party>
     */
    private function contactsNamed(string $prefix): array
    {
        return $this->inTenant($this->tenant, fn (): array => array_values(array_filter(
            $this->em()->getRepository(Party::class)->findAll(),
            static fn (Party $party): bool => str_starts_with($party->displayName(), $prefix),
        )));
    }

    /**
     * @param array<mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null, ?string $secret = null, ?string $idempotencyKey = null): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ] + (null === $idempotencyKey ? [] : ['HTTP_IDEMPOTENCY_KEY' => $idempotencyKey]), content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * Calls a tool over the MCP endpoint and answers its result.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $name, array $arguments): array
    {
        if (null === $this->session) {
            $this->mcp('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        }
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
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->writer,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
        ];
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
            $key = \is_string($key) && ctype_digit($key) ? (int) $key : $key;
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
