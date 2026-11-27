<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Integration\Work;

use Foresi\Catalog\Contract\Field\ProductExtension;
use Foresi\Deals\Contract\Field\DealExtension;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Application\ApiTokens;
use Foresi\Identity\Application\OAuth\OAuthStore;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiToken;
use Foresi\Identity\Domain\ApiTokenSecret;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\OAuthClientKind;
use Foresi\Identity\Domain\Role;
use Foresi\Party\Contract\Field\ContactExtension;
use Foresi\Party\Domain\Party;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Tests\Shared\Fixtures\Field\LentFields;
use Foresi\Tests\Work\Infrastructure\Api\WorkApiTestCase;
use Foresi\Work\Application\ManageSharing;
use Foresi\Work\Contract\Field\ProjectExtension;
use Foresi\Work\Contract\Field\TaskExtension;
use Psr\Clock\ClockInterface;

/**
 * The custom fields of contacts, deals, products, projects and tasks over the REST
 * API and MCP (ADR 0045), with the test-only fields lent to the record: every read
 * carries custom_fields by key, a create sets them, an update changes the keys it
 * sends, and a refusal names custom_fields.<key> with its reason. A field off the API
 * is left out and refused, a guest sees only the fields open to guests, and the
 * definitions are listed and described in the schemas.
 */
final class CustomFieldApiTest extends WorkApiTestCase
{
    private const array PERSON = [
        'kind' => 'person',
        'display_name' => 'Petra Example',
        'street' => 'Example Street 1',
        'city' => 'Example City',
        'postal_code' => '100 00',
        'country_code' => 'CZ',
    ];

    /** Every field of the fixture with no value. */
    private const array EMPTY = [
        'test_fields_code' => null,
        'test_fields_long_note' => null,
        'test_fields_seats' => null,
        'test_fields_weight' => null,
        'test_fields_ratio' => null,
        'test_fields_due' => null,
        'test_fields_seen_at' => null,
        'test_fields_flag' => null,
        'test_fields_site' => null,
        'test_fields_size' => null,
        'test_fields_tags' => null,
        'test_fields_contact' => null,
        'test_fields_owner' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued(array_map(ApiScope::from(...), ['contacts:write', 'sales:write', 'catalog:write', 'projects:write', 'tasks:write']));
    }

    public function testAContactCarriesItsCustomFieldsOnEveryRead(): void
    {
        $this->lend(ContactExtension::RECORD);
        $sent = [
            'test_fields_code' => 'A-1',
            'test_fields_long_note' => 'Two lines.',
            'test_fields_seats' => 4,
            'test_fields_weight' => 12.5,
            'test_fields_due' => '2026-12-01',
            'test_fields_seen_at' => '2026-11-26T14:30:00+01:00',
            'test_fields_flag' => true,
            'test_fields_site' => 'https://example.com',
            'test_fields_size' => 'large',
            'test_fields_tags' => ['red', 'blue'],
            'test_fields_contact' => $this->customerId,
        ];

        $this->send('POST', '/api/v1/contacts', self::PERSON + ['custom_fields' => $sent]);

        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        $fields = $this->at('data', 'custom_fields');
        self::assertIsArray($fields);
        self::assertSame(array_keys(self::EMPTY), array_keys($fields));
        $seen = $fields['test_fields_seen_at'];
        self::assertIsString($seen);
        self::assertSame(new \DateTimeImmutable('2026-11-26T14:30:00+01:00')->getTimestamp(), new \DateTimeImmutable($seen)->getTimestamp());
        $expected = ['test_fields_seen_at' => $seen] + $sent + self::EMPTY;
        ksort($expected);
        $read = $fields;
        ksort($read);
        self::assertSame($expected, $read);
        self::assertNull($fields['test_fields_ratio']);
        self::assertNull($fields['test_fields_owner']);

        $this->get('/api/v1/contacts/'.$id, secret: $this->writer);
        self::assertSame($fields, $this->at('data', 'custom_fields'));
        $this->get('/api/v1/contacts', ['limit' => '1'], $this->writer);
        self::assertSame($fields, $this->at('data', 0, 'custom_fields'));
        self::assertSame($fields, self::in($this->mcpTool('foresi_get_contact', ['id' => $id]), 'structuredContent', 'data', 'custom_fields'));
        self::assertSame($fields, self::in($this->mcpTool('foresi_search_contacts', ['limit' => 1]), 'structuredContent', 'data', 'items', 0, 'custom_fields'));
        // A contact without values has every field, each null.
        $this->get('/api/v1/contacts/'.$this->customerId, secret: $this->writer);
        self::assertSame(self::EMPTY, $this->at('data', 'custom_fields'));
    }

    public function testAnUpdateChangesTheKeysItSendsAndNullClearsOne(): void
    {
        $this->lend(ContactExtension::RECORD);
        $id = $this->createdContact(['test_fields_code' => 'A-1', 'test_fields_size' => 'large', 'test_fields_due' => '2026-12-01']);

        $this->send('PATCH', '/api/v1/contacts/'.$id, ['custom_fields' => ['test_fields_size' => 'small', 'test_fields_code' => null]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['test_fields_code' => null, 'test_fields_due' => '2026-12-01', 'test_fields_size' => 'small'], $this->values($this->at('data', 'custom_fields'), 'test_fields_code', 'test_fields_due', 'test_fields_size'));
        self::assertSame(['test_fields_size', 'test_fields_code'], $this->eventKeys('contacts.contact.updated', $id));

        // An update without custom_fields keeps them.
        $this->send('PATCH', '/api/v1/contacts/'.$id, ['phone' => '+420 111 111 111']);
        self::assertSame('small', $this->at('data', 'custom_fields', 'test_fields_size'));
        self::assertSame([], $this->eventKeys('contacts.contact.updated', $id));

        $call = $this->mcpTool('foresi_update_contact', ['contact_id' => $id, 'custom_fields' => ['test_fields_tags' => ['blue']]]);
        self::assertFalse($call['isError'] ?? false, json_encode($call, \JSON_THROW_ON_ERROR));
        self::assertSame(['test_fields_size' => 'small', 'test_fields_tags' => ['blue']], $this->values(self::in($call, 'structuredContent', 'data', 'custom_fields'), 'test_fields_size', 'test_fields_tags'));
    }

    public function testAWriteRefusesAValueAtItsKeyAndStoresNothing(): void
    {
        $this->lend(ContactExtension::RECORD, ['test_fields_code']);

        $this->send('POST', '/api/v1/contacts', self::PERSON);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'custom_fields.test_fields_code', 'reason' => 'field_required']);
        $this->send('POST', '/api/v1/contacts', self::PERSON + ['custom_fields' => ['test_fields_code' => 'A-1', 'test_fields_due' => '2026-13-01']]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'custom_fields.test_fields_due', 'reason' => 'invalid_value']);
        $this->send('POST', '/api/v1/contacts', self::PERSON + ['custom_fields' => ['test_fields_code' => 'A-1', 'test_fields_size' => 'huge']]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'custom_fields.test_fields_size', 'reason' => 'invalid_value']);
        $this->send('POST', '/api/v1/contacts', self::PERSON + ['custom_fields' => ['test_fields_code' => 'A-1', 'nope' => 1]]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'custom_fields.nope', 'reason' => 'unknown_field']);

        // Over MCP a value the tool's schema takes is still checked by its kind; one it does not take is refused by the protocol.
        $call = $this->mcpTool('foresi_create_contact', self::PERSON + ['custom_fields' => ['test_fields_code' => 'A-1', 'test_fields_site' => 'ftp://example.com']]);
        self::assertSame(['invalid_argument', 'custom_fields.test_fields_site', 'invalid_value'], [
            self::in($call, 'structuredContent', 'error', 'code'),
            self::in($call, 'structuredContent', 'error', 'details', 'parameter'),
            self::in($call, 'structuredContent', 'error', 'details', 'reason'),
        ]);
        self::assertSame([], $this->contactsNamed('Petra'));

        // An update needs no required field, and a refused value changes nothing of the contact.
        $id = $this->createdContact(['test_fields_code' => 'A-1']);
        $call = $this->mcpTool('foresi_update_contact', ['contact_id' => $id, 'display_name' => 'Petra Renamed', 'custom_fields' => ['test_fields_site' => 'ftp://example.com']]);
        self::assertSame('custom_fields.test_fields_site', self::in($call, 'structuredContent', 'error', 'details', 'parameter'));
        $this->get('/api/v1/contacts/'.$id, secret: $this->writer);
        self::assertSame('Petra Example', $this->at('data', 'name'));
    }

    public function testAFieldOffTheApiIsLeftOutAndRefusedAndNotRequired(): void
    {
        $this->lend(ContactExtension::RECORD, ['test_fields_code'], ['test_fields_code']);

        $this->send('POST', '/api/v1/contacts', self::PERSON);
        self::assertResponseStatusCodeSame(201);
        $fields = $this->at('data', 'custom_fields');
        self::assertIsArray($fields);
        self::assertArrayNotHasKey('test_fields_code', $fields);
        self::assertArrayHasKey('test_fields_size', $fields);

        $this->send('POST', '/api/v1/contacts', ['display_name' => 'Petra Again'] + self::PERSON + ['custom_fields' => ['test_fields_code' => 'A-1']]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'custom_fields.test_fields_code', 'reason' => 'unknown_field']);

        $this->get('/api/v1/fields', ['record' => 'contact'], $this->writer);
        self::assertNotContains('test_fields_code', $this->column('key', 'data'));
    }

    public function testTheSchemasDescribeTheFields(): void
    {
        $this->lend(ContactExtension::RECORD, ['test_fields_code']);

        $tools = self::in($this->mcpRequest('tools/list'), 'result', 'tools');
        self::assertIsArray($tools);
        $create = array_values(array_filter($tools, static fn (mixed $tool): bool => \is_array($tool) && 'foresi_create_contact' === ($tool['name'] ?? null)))[0] ?? null;
        $property = self::in($create, 'inputSchema', 'properties', 'custom_fields');
        self::assertSame('object', self::in($property, 'type'));
        self::assertSame(['string', 'null'], self::in($property, 'properties', 'test_fields_due', 'type'));
        self::assertSame('date', self::in($property, 'properties', 'test_fields_due', 'format'));
        self::assertSame(['small', 'large', null], self::in($property, 'properties', 'test_fields_size', 'enum'));
        self::assertStringContainsString('Required when the record is created.', $this->text($property, 'properties', 'test_fields_code', 'description'));

        $this->client->request('GET', '/api/v1/openapi.json');
        self::assertResponseIsSuccessful();
        $documented = $this->at('components', 'schemas', 'ContactCustomFields', 'properties');
        self::assertSame(['string', true], [self::in($documented, 'test_fields_code', 'type'), self::in($documented, 'test_fields_code', 'nullable')]);
        self::assertSame(['array', true], [self::in($documented, 'test_fields_tags', 'type'), self::in($documented, 'test_fields_tags', 'nullable')]);
        self::assertSame('#/components/schemas/ContactCustomFields', $this->at('paths', '/api/v1/contacts', 'post', 'requestBody', 'content', 'application/json', 'schema', 'properties', 'custom_fields', '$ref'));
        self::assertSame('#/components/schemas/ContactCustomFields', $this->at('components', 'schemas', 'Contact', 'properties', 'custom_fields', '$ref'));
    }

    public function testTheDefinitionsAreListedByRecordAndScope(): void
    {
        $this->lend(ContactExtension::RECORD);

        $this->get('/api/v1/fields', [], $this->writer);
        self::assertResponseIsSuccessful();
        // The fields of every record kind the token reads: the contacts', and those of the test-only record.
        self::assertSame([...array_fill(0, 13, 'contact'), ...array_fill(0, 13, 'fixture_note')], $this->column('record', 'data'));
        self::assertSame([
            'key' => 'test_fields_size',
            'record' => 'contact',
            'kind' => 'select',
            'label' => 'test.field.size',
            'options' => [['key' => 'small', 'label' => 'test.size.small'], ['key' => 'large', 'label' => 'test.size.large']],
            'module' => 'test-fields',
            'required' => false,
            'filterable' => true,
            'sortable' => false,
            'searchable' => false,
            'guests' => true,
        ], $this->at('data', 9));
        $this->get('/api/v1/fields', ['record' => 'contact'], $this->writer);
        self::assertSame(array_keys(self::EMPTY), $this->column('key', 'data'));
        $this->get('/api/v1/fields', ['record' => 'deal'], $this->writer);
        self::assertSame([], $this->at('data'));
        $this->get('/api/v1/fields', ['record' => 'invoice'], $this->writer);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'record']);

        // A token that cannot read contacts lists none of their fields, and is refused them by name.
        $tasks = $this->issued([ApiScope::from('tasks:read')]);
        $this->get('/api/v1/fields', [], $tasks);
        self::assertSame([], $this->at('data'));
        $this->get('/api/v1/fields', ['record' => 'contact'], $tasks);
        $this->assertProblem(403, 'insufficient_scope');

        $listed = $this->mcpTool('foresi_list_fields', ['record' => 'contact']);
        self::assertSame(array_keys(self::EMPTY), array_column($this->listAt($listed, 'structuredContent', 'data', 'items'), 'key'));
        self::assertStringContainsString('record', $this->text($this->mcpRequest('tools/call', ['name' => 'foresi_list_fields', 'arguments' => ['record' => 'invoice']]), 'error', 'message'));
    }

    public function testADealCarriesItsCustomFields(): void
    {
        $this->lend(DealExtension::RECORD, ['test_fields_code']);
        $this->send('POST', '/api/v1/sales/stages', ['name' => 'Inquiry']);
        self::assertResponseStatusCodeSame(201);

        $this->send('POST', '/api/v1/sales/deals', ['title' => 'Roof audit', 'client_party_id' => $this->customerId]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'custom_fields.test_fields_code', 'reason' => 'field_required']);
        $this->send('POST', '/api/v1/sales/deals', ['title' => 'Roof audit', 'client_party_id' => $this->customerId, 'custom_fields' => ['test_fields_code' => 'D-1', 'test_fields_due' => '2026-12-01']]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertSame(['test_fields_code' => 'D-1', 'test_fields_due' => '2026-12-01'], $this->values($this->at('data', 'custom_fields'), 'test_fields_code', 'test_fields_due'));
        $this->get('/api/v1/sales/deals', [], $this->writer);
        self::assertSame('D-1', $this->at('data', 0, 'custom_fields', 'test_fields_code'));

        $call = $this->mcpTool('foresi_update_deal', ['deal_id' => $id, 'custom_fields' => ['test_fields_size' => 'small']]);
        self::assertFalse($call['isError'] ?? false, json_encode($call, \JSON_THROW_ON_ERROR));
        self::assertSame(['test_fields_code' => 'D-1', 'test_fields_size' => 'small'], $this->values(self::in($call, 'structuredContent', 'data', 'custom_fields'), 'test_fields_code', 'test_fields_size'));
        self::assertSame(['test_fields_size'], $this->eventKeys('sales.deal.updated', $id));
        self::assertSame('small', self::in($this->mcpTool('foresi_search_deals', []), 'structuredContent', 'data', 'items', 0, 'custom_fields', 'test_fields_size'));
        $refused = $this->mcpTool('foresi_update_deal', ['deal_id' => $id, 'custom_fields' => ['test_fields_site' => 'mailto:sales@example.com']]);
        self::assertSame('custom_fields.test_fields_site', self::in($refused, 'structuredContent', 'error', 'details', 'parameter'));
    }

    public function testAProductCarriesItsCustomFields(): void
    {
        $this->lend(ProductExtension::RECORD);

        $this->send('POST', '/api/v1/catalog/products', ['name' => 'Roof audit', 'custom_fields' => ['test_fields_tags' => ['red'], 'test_fields_seats' => 2]]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertSame(['test_fields_seats' => 2, 'test_fields_tags' => ['red']], $this->values($this->at('data', 'custom_fields'), 'test_fields_seats', 'test_fields_tags'));
        $this->get('/api/v1/catalog/products', [], $this->writer);
        self::assertSame(['red'], $this->at('data', 0, 'custom_fields', 'test_fields_tags'));
        self::assertSame(2, self::in($this->mcpTool('foresi_get_product', ['id' => $id]), 'structuredContent', 'data', 'custom_fields', 'test_fields_seats'));

        $call = $this->mcpTool('foresi_update_product', ['product_id' => $id, 'custom_fields' => ['test_fields_tags' => null]]);
        self::assertFalse($call['isError'] ?? false, json_encode($call, \JSON_THROW_ON_ERROR));
        self::assertNull(self::in($call, 'structuredContent', 'data', 'custom_fields', 'test_fields_tags'));
        self::assertSame(['test_fields_tags'], $this->eventKeys('catalog.product.updated', $id));
        $this->send('PATCH', '/api/v1/catalog/products/'.$id, ['custom_fields' => ['test_fields_tags' => ['green']]]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'custom_fields.test_fields_tags', 'reason' => 'invalid_value']);
    }

    public function testAProjectCarriesItsCustomFields(): void
    {
        $this->lend(ProjectExtension::RECORD);

        $this->send('POST', '/api/v1/projects', ['name' => 'Launch', 'custom_fields' => ['test_fields_code' => 'P-1', 'test_fields_contact' => $this->customerId]]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);
        self::assertSame(['test_fields_code' => 'P-1', 'test_fields_contact' => $this->customerId], $this->values($this->at('data', 'custom_fields'), 'test_fields_code', 'test_fields_contact'));
        self::assertSame('P-1', self::in($this->mcpTool('foresi_search_projects', []), 'structuredContent', 'data', 'items', 0, 'custom_fields', 'test_fields_code'));

        $call = $this->mcpTool('foresi_update_project', ['project_id' => $id, 'custom_fields' => ['test_fields_contact' => '01963c5e-0000-7000-8000-000000000001']]);
        self::assertSame(['custom_fields.test_fields_contact', 'invalid_value'], [self::in($call, 'structuredContent', 'error', 'details', 'parameter'), self::in($call, 'structuredContent', 'error', 'details', 'reason')]);
        $this->send('PATCH', '/api/v1/projects/'.$id, ['custom_fields' => ['test_fields_code' => 'P-2']]);
        self::assertResponseIsSuccessful();
        self::assertSame('P-2', $this->at('data', 'custom_fields', 'test_fields_code'));
        self::assertSame(['test_fields_code'], $this->eventKeys('work.project.updated', $id));
    }

    public function testATaskChangesSeveralFieldsInOneChange(): void
    {
        $this->lend(TaskExtension::RECORD);
        $project = $this->project();

        $call = $this->mcpTool('foresi_create_task', ['project_id' => $project->id(), 'title' => 'Write the copy', 'custom_fields' => ['test_fields_flag' => false, 'test_fields_ratio' => 0.5]]);
        self::assertFalse($call['isError'] ?? false, json_encode($call, \JSON_THROW_ON_ERROR));
        $id = self::in($call, 'structuredContent', 'data', 'id');
        self::assertIsString($id);
        self::assertSame(['test_fields_flag' => false, 'test_fields_ratio' => 0.5], $this->values(self::in($call, 'structuredContent', 'data', 'custom_fields'), 'test_fields_flag', 'test_fields_ratio'));

        $this->send('PATCH', '/api/v1/tasks/'.$id, ['title' => 'Write the whole copy', 'custom_fields' => ['test_fields_flag' => true, 'test_fields_code' => 'T-1', 'test_fields_ratio' => 0.5]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['test_fields_code' => 'T-1', 'test_fields_flag' => true], $this->values($this->at('data', 'custom_fields'), 'test_fields_code', 'test_fields_flag'));
        // One work.task.updated names the keys whose value changed; the title has its own.
        self::assertSame(['test_fields_flag', 'test_fields_code'], $this->eventKeys('work.task.updated', $id));
        $this->get('/api/v1/tasks', [], $this->writer);
        self::assertSame('T-1', $this->at('data', 0, 'custom_fields', 'test_fields_code'));

        // A refused value leaves the task as it was, the title sent with it too.
        $this->send('PATCH', '/api/v1/tasks/'.$id, ['title' => 'Something else', 'custom_fields' => ['test_fields_seen_at' => '2026-11-26 14:30']]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'custom_fields.test_fields_seen_at', 'reason' => 'invalid_value']);
        $this->get('/api/v1/tasks/'.$id, secret: $this->writer);
        self::assertSame('Write the whole copy', $this->at('data', 'title'));
    }

    public function testAGuestSeesAndWritesOnlyTheFieldsOpenToGuests(): void
    {
        $this->lend(TaskExtension::RECORD);
        $guest = $this->fixtures->user('guest@example.com');
        $this->fixtures->join($guest, Role::Guest);
        $project = $this->chosenProject('Client portal');
        $task = $this->task($project, 'Approve the logo');
        $this->as(fn () => $this->service(ManageSharing::class)->shareTask($task, $this->membershipOf($guest)->id()));
        $this->writer = $this->connection($guest->id());

        $read = $this->mcpTool('foresi_get_task', ['task_id' => $task]);
        self::assertSame(['test_fields_size' => null], self::in($read, 'structuredContent', 'data', 'custom_fields'));
        $changed = $this->mcpTool('foresi_update_task', ['task_id' => $task, 'custom_fields' => ['test_fields_size' => 'large']]);
        self::assertFalse($changed['isError'] ?? false, json_encode($changed, \JSON_THROW_ON_ERROR));
        self::assertSame(['test_fields_size' => 'large'], self::in($changed, 'structuredContent', 'data', 'custom_fields'));
        // A field closed to guests is not in the guest's schema of the tool, so a write of it is refused.
        $tools = self::in($this->mcpRequest('tools/list'), 'result', 'tools');
        self::assertIsArray($tools);
        $update = array_values(array_filter($tools, static fn (mixed $tool): bool => \is_array($tool) && 'foresi_update_task' === ($tool['name'] ?? null)))[0] ?? null;
        self::assertSame(['test_fields_size'], array_keys((array) self::in($update, 'inputSchema', 'properties', 'custom_fields', 'properties')));
        $refused = $this->mcpRequest('tools/call', ['name' => 'foresi_update_task', 'arguments' => ['task_id' => $task, 'custom_fields' => ['test_fields_code' => 'G-1']]]);
        self::assertStringContainsString('test_fields_code', $this->text($refused, 'error', 'message'));
        self::assertSame(['test_fields_size'], array_column($this->listAt($this->mcpTool('foresi_list_fields', ['record' => 'task']), 'structuredContent', 'data', 'items'), 'key'));
    }

    /**
     * @param list<string> $required
     * @param list<string> $offApi
     */
    private function lend(string $record, array $required = [], array $offApi = []): Fields
    {
        return LentFields::lend(self::getContainer(), $record, $required, $offApi);
    }

    /**
     * @param array<string, mixed> $customFields
     */
    private function createdContact(array $customFields): string
    {
        $this->send('POST', '/api/v1/contacts', self::PERSON + ['custom_fields' => $customFields]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->at('data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed> the values of these keys, in this order
     */
    private function values(mixed $fields, string ...$keys): array
    {
        self::assertIsArray($fields);
        $values = [];
        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $fields);
            $values[$key] = $fields[$key];
        }

        return $values;
    }

    /**
     * @return list<mixed> the custom fields the last event of the name and subject names
     */
    private function eventKeys(string $name, string $subject): array
    {
        $data = $this->em()->getConnection()->fetchOne(
            'SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ? AND '.EventLogTable::SUBJECT_ID.' = ? ORDER BY '.EventLogTable::ID.' DESC',
            [$name, $subject],
        );
        self::assertIsString($data, $name);
        $payload = json_decode($data, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['custom_fields']);

        return array_values($payload['custom_fields']);
    }

    private function text(mixed $value, string|int ...$path): string
    {
        $text = self::in($value, ...$path);
        self::assertIsString($text);

        return $text;
    }

    /**
     * @return list<array<mixed>>
     */
    private function listAt(mixed $value, string|int ...$path): array
    {
        $list = self::in($value, ...$path);
        self::assertIsArray($list);

        return array_values(array_filter($list, \is_array(...)));
    }

    /**
     * @return list<Party>
     */
    private function contactsNamed(string $prefix): array
    {
        return array_values(array_filter(
            $this->em()->getRepository(Party::class)->findAll(),
            static fn (Party $party): bool => str_starts_with($party->displayName(), $prefix),
        ));
    }

    /**
     * An OAuth connection of the person, as the token endpoint makes it.
     */
    private function connection(string $userId): string
    {
        return $this->flushed(function () use ($userId): string {
            $now = $this->service(ClockInterface::class)->now();
            $client = new OAuthClient('foresi_client_'.bin2hex(random_bytes(8)), OAuthClientKind::Registered, 'Example Agent', ['https://agent.example.com/callback'], $now);
            $this->service(OAuthStore::class)->addClient($client);
            $secret = ApiTokenSecret::generateOAuth();
            $this->service(ApiTokens::class)->add(new ApiToken($userId, 'Example Agent', [ApiScope::write()], ApiTokenSecret::hash($secret), ApiTokenSecret::lastFour($secret), $now, $now->modify('+1 hour'), $client->id()));

            return $secret;
        });
    }
}
