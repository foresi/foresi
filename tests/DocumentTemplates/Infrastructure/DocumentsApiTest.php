<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\DocumentTemplates\Infrastructure;

use Foresi\DocumentTemplates\Application\DocumentVariables;
use Foresi\DocumentTemplates\Application\LinkTargets;
use Foresi\Events\Infrastructure\Dbal\EventLogTable;
use Foresi\Identity\Application\ApiTokens;
use Foresi\Identity\Application\GuestScopes;
use Foresi\Identity\Application\OAuth\OAuthStore;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiToken;
use Foresi\Identity\Domain\ApiTokenSecret;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\OAuthClientKind;
use Foresi\Identity\Domain\Role;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\DocumentTemplates\Support\FakeFirmVariables;
use Foresi\Tests\DocumentTemplates\Support\FakeLinkTarget;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Documents over REST and MCP: the writes declared once with #[Operation]
 * under documents:write, the reads under documents:read, the file downloads,
 * and both for the owners and members only (ADR 0041). The contacts a document
 * links to are a test double: Party offers the real ones.
 */
final class DocumentsApiTest extends ApiTestCase
{
    private const string BODY = '<h1>Mutual NDA</h1><p>{{ firm.name }}</p>';

    private string $writer;
    private string $contactId;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-11-22 10:00:00', 'UTC'));
        $contacts = new FakeLinkTarget();
        $this->contactId = Id::generate();
        $contacts->add($this->contactId, 'Example Client a.s.');
        self::getContainer()->set(LinkTargets::class, new LinkTargets([$contacts]));
        self::getContainer()->set(DocumentVariables::class, new DocumentVariables([new FakeFirmVariables()]));
        $this->writer = $this->issued([ApiScope::from('documents:write')]);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testEveryWriteOverRestAndTheReadsAnswerWhatTheWritesDid(): void
    {
        $this->send('POST', '/api/v1/document-types', ['name' => 'Service contract', 'code' => 'service_contract']);
        self::assertResponseStatusCodeSame(201);
        $typeId = $this->string('data', 'id');
        $this->send('PATCH', '/api/v1/document-types/'.$typeId, ['name' => 'Service agreement', 'needs_signing' => true, 'signature_level' => 'advanced']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Service agreement', 'advanced'], [$this->at('data', 'name'), $this->at('data', 'signature_level')]);
        $type = $this->json()['data'];

        $this->get('/api/v1/document-types');
        self::assertResponseIsSuccessful();
        self::assertSame([$type], $this->at('data'));

        $this->send('POST', '/api/v1/document-templates', ['type_id' => $typeId, 'name' => 'Service agreement, English', 'body' => '<p>Draft</p>', 'margin_top' => 25]);
        self::assertResponseStatusCodeSame(201);
        $templateId = $this->string('data', 'id');
        self::assertSame(25, $this->at('data', 'version', 'page', 'margin_top'));
        $this->send('POST', '/api/v1/document-templates/'.$templateId.'/versions', ['body' => self::BODY]);
        self::assertResponseIsSuccessful();
        self::assertSame([1, ['firm.name']], [$this->at('data', 'version', 'number'), $this->at('data', 'version', 'uses')]);

        $this->get('/api/v1/document-templates/'.$templateId);
        self::assertResponseIsSuccessful();
        self::assertSame([['name' => 'firm.name', 'label' => 'Firm name', 'example' => 'Example Firm s.r.o.', 'image' => false]], $this->at('data', 'variables'));
        $template = $this->json()['data'];
        $this->get('/api/v1/document-templates', ['type_id' => $typeId]);
        self::assertSame([$templateId], $this->ids());
        self::assertIsArray($template);
        self::assertSame(['id' => $templateId, 'type_id' => $typeId, 'name' => $template['name'], 'archived' => false, 'version' => ['id' => $this->at('data', 0, 'version', 'id'), 'number' => 1, 'frozen' => false]], $this->at('data', 0), 'A row has the latest version without its body.');
        $this->get('/api/v1/document-templates', ['type_id' => Id::generate()]);
        self::assertSame([], $this->ids());

        $this->send('POST', '/api/v1/documents', ['type_id' => $typeId, 'template_id' => $templateId, 'title' => 'Draft agreement', 'links' => [['kind' => 'contact', 'id' => $this->contactId]]]);
        self::assertResponseStatusCodeSame(201);
        $documentId = $this->string('data', 'id');
        $this->send('PATCH', '/api/v1/documents/'.$documentId, ['title' => 'Agreement with Example Client']);
        self::assertSame(['Agreement with Example Client', 'draft'], [$this->at('data', 'title'), $this->at('data', 'status')]);

        $this->send('POST', '/api/v1/documents/'.$documentId.'/signers', ['name' => 'Jane Client', 'side' => 'theirs', 'order' => 2, 'email' => 'jane@example.com']);
        self::assertResponseStatusCodeSame(201);
        $theirs = $this->string('data', 'id');
        $this->send('POST', '/api/v1/documents/'.$documentId.'/signers', ['name' => 'Someone Else', 'side' => 'ours']);
        $extra = $this->string('data', 'id');
        $this->send('DELETE', '/api/v1/documents/'.$documentId.'/signers/'.$extra);
        self::assertResponseIsSuccessful();
        self::assertSame([$theirs], array_column((array) $this->at('data', 'signers'), 'id'));
        $this->send('POST', '/api/v1/documents/'.$documentId.'/signers', ['name' => 'John Firm', 'side' => 'ours']);
        $ours = $this->string('data', 'id');
        $this->send('PATCH', '/api/v1/documents/'.$documentId.'/signers/'.$ours, ['name' => 'John Firm', 'side' => 'ours', 'email' => 'john@example.com']);
        self::assertSame('john@example.com', $this->at('data', 'email'));

        $this->send('POST', '/api/v1/documents/'.$documentId.'/generate');
        self::assertResponseIsSuccessful();
        self::assertSame(['ready', ['firm' => ['name' => 'Example Firm s.r.o.']]], [$this->at('data', 'status'), $this->at('data', 'values')]);
        self::assertIsString($this->at('data', 'files', 'pdf', 'sha256'));
        self::assertNull($this->at('data', 'files', 'signed'));

        $this->get('/api/v1/documents/'.$documentId.'/files/pdf');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $pdf = $this->bytes();
        self::assertStringStartsWith('%PDF-', $pdf);
        $this->get('/api/v1/documents/'.$documentId.'/files/signed');
        $this->assertProblem(404, 'not_found');
        $this->get('/api/v1/documents/'.$documentId.'/files/contract');
        self::assertResponseStatusCodeSame(404);

        $this->send('POST', '/api/v1/documents/'.$documentId.'/mark-sent');
        self::assertSame('out_for_signature', $this->at('data', 'status'));
        $this->send('POST', '/api/v1/documents/'.$documentId.'/signed-copy', ['file_name' => 'agreement-signed.pdf', 'content_base64' => base64_encode($pdf)]);
        self::assertResponseIsSuccessful();
        $this->send('POST', '/api/v1/documents/'.$documentId.'/signers/'.$theirs.'/mark-signed');
        self::assertSame('out_for_signature', $this->at('data', 'status'));
        $this->send('POST', '/api/v1/documents/'.$documentId.'/signers/'.$ours.'/mark-signed');
        self::assertSame('completed', $this->at('data', 'status'));

        $this->get('/api/v1/documents/'.$documentId.'/files/signed');
        self::assertResponseIsSuccessful();
        self::assertSame($pdf, $this->bytes());

        $this->get('/api/v1/documents/'.$documentId);
        self::assertResponseIsSuccessful();
        $document = $this->json()['data'];
        self::assertIsArray($document);
        self::assertSame([['kind' => 'contact', 'id' => $this->contactId, 'available' => true, 'label' => 'Example Client a.s.', 'url' => '/contacts/'.$this->contactId]], $this->at('data', 'links'));
        self::assertSame([$ours, $theirs], array_column((array) $this->at('data', 'signers'), 'id'), 'In signing order.');

        $this->send('POST', '/api/v1/documents/'.$documentId.'/generate-again');
        self::assertResponseStatusCodeSame(201);
        $againId = $this->string('data', 'id');
        self::assertSame('draft', $this->at('data', 'status'));
        $this->send('POST', '/api/v1/documents/'.$againId.'/generate');
        $declining = $this->string('data', 'signers', 1, 'id');
        $this->send('POST', '/api/v1/documents/'.$againId.'/signers/'.$declining.'/mark-declined');
        self::assertSame('declined', $this->at('data', 'status'));
        $this->send('POST', '/api/v1/documents/'.$documentId.'/generate-again');
        $cancelledId = $this->string('data', 'id');
        $this->send('POST', '/api/v1/documents/'.$cancelledId.'/cancel');
        self::assertSame('cancelled', $this->at('data', 'status'));

        $this->get('/api/v1/documents');
        self::assertSame([$cancelledId, $againId, $documentId], $this->ids(), 'Newest first.');
        $this->get('/api/v1/documents', ['status' => 'completed']);
        self::assertSame([$documentId], $this->ids());
        $row = $this->at('data', 0);
        self::assertIsArray($row);
        self::assertSame(array_intersect_key($document, $row), $row, 'A row is a part of the detail.');
        $this->get('/api/v1/documents', ['type_id' => $typeId, 'linked_kind' => 'contact', 'linked_id' => $this->contactId, 'limit' => '2', 'offset' => '1']);
        self::assertSame([$againId, $documentId], $this->ids());
        $this->get('/api/v1/documents', ['linked_kind' => 'contact', 'linked_id' => Id::generate()]);
        self::assertSame([], $this->ids());
        $this->get('/api/v1/documents', ['status' => 'signed']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'status']);
        $this->get('/api/v1/documents', ['linked_kind' => 'contact']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'linked_id']);
        $this->get('/api/v1/documents', ['limit' => '500']);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'limit']);
        $this->get('/api/v1/documents/'.Id::generate());
        $this->assertProblem(404, 'not_found');

        $this->send('POST', '/api/v1/document-templates/'.$templateId.'/archive');
        self::assertTrue($this->at('data', 'archived'));
        $this->get('/api/v1/document-templates');
        self::assertSame([], $this->ids(), 'Archived templates are left out.');
        $this->get('/api/v1/document-templates', ['include_archived' => 'true']);
        self::assertSame([$templateId], $this->ids());
        $this->send('POST', '/api/v1/document-types/'.$typeId.'/archive');
        self::assertTrue($this->at('data', 'archived'));
        $this->get('/api/v1/document-types');
        self::assertSame([], $this->ids());
        $this->get('/api/v1/document-types', ['include_archived' => 'true']);
        self::assertSame([$typeId], $this->ids());
    }

    public function testEveryWriteOverMcpAndTheReadToolsAnswerAsRest(): void
    {
        $type = $this->call('foresi_create_document_type', ['name' => 'Service contract', 'code' => 'service_contract']);
        $typeId = $this->id($type);
        $this->call('foresi_update_document_type', ['type_id' => $typeId, 'name' => 'Service agreement', 'needs_signing' => true, 'signature_level' => 'simple']);
        $template = $this->call('foresi_create_document_template', ['type_id' => $typeId, 'name' => 'Service agreement, English', 'body' => '<p>Draft</p>']);
        $templateId = $this->id($template);
        $this->call('foresi_save_document_template_version', ['template_id' => $templateId, 'body' => self::BODY]);
        $document = $this->call('foresi_create_document', ['type_id' => $typeId, 'template_id' => $templateId, 'title' => 'Draft agreement', 'links' => [['kind' => 'contact', 'id' => $this->contactId]]]);
        $documentId = $this->id($document);
        $this->call('foresi_update_document', ['document_id' => $documentId, 'title' => 'Agreement with Example Client']);
        $signerId = $this->id($this->call('foresi_add_document_signer', ['document_id' => $documentId, 'name' => 'Jane Client', 'side' => 'theirs']));
        $extraId = $this->id($this->call('foresi_add_document_signer', ['document_id' => $documentId, 'name' => 'Someone Else', 'side' => 'ours']));
        $this->call('foresi_update_document_signer', ['document_id' => $documentId, 'signer_id' => $signerId, 'name' => 'Jane Client', 'side' => 'theirs', 'email' => 'jane@example.com']);
        $this->call('foresi_remove_document_signer', ['document_id' => $documentId, 'signer_id' => $extraId]);
        $this->call('foresi_generate_document', ['document_id' => $documentId]);
        $this->call('foresi_mark_document_sent', ['document_id' => $documentId]);
        $this->get('/api/v1/documents/'.$documentId.'/files/pdf');
        $pdf = $this->bytes();
        $this->call('foresi_attach_signed_document_copy', ['document_id' => $documentId, 'file_name' => 'agreement-signed.pdf', 'content_base64' => base64_encode($pdf)]);
        $completed = $this->call('foresi_mark_document_signer_signed', ['document_id' => $documentId, 'signer_id' => $signerId]);
        self::assertSame('completed', $this->in($completed, 'structuredContent', 'data', 'status'));
        $againId = $this->id($this->call('foresi_generate_document_again', ['document_id' => $documentId]));
        $this->call('foresi_generate_document', ['document_id' => $againId]);
        $declinedSigner = $this->in($this->call('foresi_get_document', ['document_id' => $againId]), 'structuredContent', 'data', 'signers', 0, 'id');
        self::assertIsString($declinedSigner);
        $declined = $this->call('foresi_mark_document_signer_declined', ['document_id' => $againId, 'signer_id' => $declinedSigner]);
        self::assertSame('declined', $this->in($declined, 'structuredContent', 'data', 'status'));
        $cancelId = $this->id($this->call('foresi_generate_document_again', ['document_id' => $againId]));
        $cancelled = $this->call('foresi_cancel_document', ['document_id' => $cancelId]);
        self::assertSame('cancelled', $this->in($cancelled, 'structuredContent', 'data', 'status'));

        $this->get('/api/v1/documents/'.$documentId);
        $rest = $this->json()['data'];
        self::assertEquals($rest, $this->in($this->call('foresi_get_document', ['document_id' => $documentId]), 'structuredContent', 'data'));
        $this->get('/api/v1/documents', ['status' => 'completed']);
        $rows = $this->json()['data'];
        self::assertEquals(['items' => $rows], $this->in($this->call('foresi_list_documents', ['status' => 'completed']), 'structuredContent', 'data'));
        $linked = $this->call('foresi_list_documents', ['linked_kind' => 'contact', 'linked_id' => $this->contactId, 'limit' => 2]);
        self::assertSame([$cancelId, $againId], array_column((array) $this->in($linked, 'structuredContent', 'data', 'items'), 'id'));
        $this->get('/api/v1/document-types');
        $rest = $this->json()['data'];
        self::assertEquals(['items' => $rest], $this->in($this->call('foresi_list_document_types', []), 'structuredContent', 'data'));
        $this->get('/api/v1/document-templates', ['type_id' => $typeId]);
        $rest = $this->json()['data'];
        self::assertEquals(['items' => $rest], $this->in($this->call('foresi_list_document_templates', ['type_id' => $typeId]), 'structuredContent', 'data'));
        $this->get('/api/v1/document-templates/'.$templateId);
        $rest = $this->json()['data'];
        self::assertEquals($rest, $this->in($this->call('foresi_get_document_template', ['template_id' => $templateId]), 'structuredContent', 'data'));

        $missing = $this->tool($this->writer, 'foresi_get_document', ['document_id' => Id::generate()]);
        self::assertSame('not_found', $this->in($missing, 'structuredContent', 'error', 'code'));
        $invalid = $this->tool($this->writer, 'foresi_list_documents', ['linked_kind' => 'contact']);
        self::assertSame(['invalid_argument', 'linked_id'], [$this->in($invalid, 'structuredContent', 'error', 'code'), $this->in($invalid, 'structuredContent', 'error', 'details', 'parameter')]);

        $this->call('foresi_archive_document_template', ['template_id' => $templateId]);
        $this->call('foresi_archive_document_type', ['type_id' => $typeId]);
        self::assertSame([], $this->in($this->call('foresi_list_document_types', []), 'structuredContent', 'data', 'items'));
        self::assertCount(1, (array) $this->in($this->call('foresi_list_document_types', ['include_archived' => true]), 'structuredContent', 'data', 'items'));
    }

    public function testWithoutDocumentsWriteTheWritesAreRefused(): void
    {
        $reader = $this->issued([ApiScope::from('documents:read')]);
        $this->send('POST', '/api/v1/document-types', ['name' => 'Service contract', 'code' => 'service_contract'], $reader);
        $this->assertProblem(403, 'insufficient_scope');
        $write = $this->tool($reader, 'foresi_create_document_type', ['name' => 'Service contract', 'code' => 'service_contract']);
        self::assertTrue($write['isError'] ?? false);
        self::assertSame('insufficient_scope', $this->in($write, 'structuredContent', 'error', 'code'));

        $this->get('/api/v1/document-types', secret: $reader);
        self::assertResponseIsSuccessful();
        $this->get('/api/v1/documents', secret: $this->issued([ApiScope::from('contacts:read')]));
        $this->assertProblem(403, 'insufficient_scope');
    }

    public function testAGuestIsRefusedOnBothSurfaces(): void
    {
        $guest = $this->fixtures->user('guest@example.com');
        $this->fixtures->join($guest, Role::Guest);
        // An agent of a guest that asked for everything holds only what a guest may hold: nothing of Documents.
        $secret = $this->flushed(function () use ($guest): string {
            $now = self::getContainer()->get(ClockInterface::class)->now();
            $client = new OAuthClient('foresi_client_'.bin2hex(random_bytes(8)), OAuthClientKind::Registered, 'Example Agent', ['https://agent.example.com/callback'], $now);
            self::getContainer()->get(OAuthStore::class)->addClient($client);
            $secret = ApiTokenSecret::generateOAuth();
            self::getContainer()->get(ApiTokens::class)->add(new ApiToken($guest->id(), 'Example Agent', [ApiScope::write()], ApiTokenSecret::hash($secret), ApiTokenSecret::lastFour($secret), $now, $now->modify('+1 hour'), $client->id()));

            return $secret;
        });

        // Over REST a guest's token is never accepted (ADR 0041).
        $this->get('/api/v1/documents', secret: $secret);
        self::assertResponseStatusCodeSame(401);
        $this->send('POST', '/api/v1/document-types', ['name' => 'Service contract', 'code' => 'service_contract'], $secret);
        self::assertResponseStatusCodeSame(401);

        // Over MCP a guest connects only when some installed module opens a resource to guests
        // (Work does); without one the token is refused like over REST.
        if ([] === self::getContainer()->get(GuestScopes::class)->offered()) {
            $this->client->request('POST', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], content: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');
            self::assertResponseStatusCodeSame(401);

            return;
        }
        // The connection works, but no tool of Documents is listed or answers.
        $this->session = null;
        $this->mcp($secret, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        $tools = array_column((array) $this->in($this->mcp($secret, 'tools/list'), 'result', 'tools'), 'name');
        self::assertSame([], array_values(array_filter($tools, static fn (mixed $name): bool => \is_string($name) && str_contains($name, 'document') && !str_contains($name, 'received_document'))));
        foreach (['foresi_list_documents' => [], 'foresi_create_document_type' => ['name' => 'Service contract', 'code' => 'service_contract']] as $name => $arguments) {
            $call = $this->tool($secret, $name, $arguments);
            self::assertTrue($call['isError'] ?? false, $name);
        }
    }

    public function testMarkSignedRetriedWithTheSameKeyActsOnce(): void
    {
        [$documentId, $signerId] = $this->readyDocumentWithASigner();

        $key = Id::generate();
        $this->send('POST', '/api/v1/documents/'.$documentId.'/signers/'.$signerId.'/mark-signed', null, idempotencyKey: $key);
        self::assertResponseIsSuccessful();
        $first = $this->json();
        $this->send('POST', '/api/v1/documents/'.$documentId.'/signers/'.$signerId.'/mark-signed', null, idempotencyKey: $key);
        self::assertResponseIsSuccessful();
        self::assertSame($first, $this->json());
        self::assertCount(1, $this->events('document-templates.signer.signed'));

        [$documentId, $signerId] = $this->readyDocumentWithASigner();
        $arguments = ['document_id' => $documentId, 'signer_id' => $signerId, 'idempotency_key' => Id::generate()];
        $once = $this->tool($this->writer, 'foresi_mark_document_signer_signed', $arguments);
        $twice = $this->tool($this->writer, 'foresi_mark_document_signer_signed', $arguments);
        self::assertFalse($once['isError'] ?? false);
        self::assertEquals($this->in($once, 'structuredContent', 'data'), $this->in($twice, 'structuredContent', 'data'));
        self::assertCount(2, $this->events('document-templates.signer.signed'));
    }

    public function testACancelledDocumentGoesToTheTrashAndBackOverRestAndMcp(): void
    {
        [$documentId] = $this->readyDocumentWithASigner();

        $this->send('POST', '/api/v1/documents/'.$documentId.'/trash');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('not_cancelled', $this->at('code'));
        $this->send('POST', '/api/v1/documents/'.$documentId.'/cancel');
        $this->send('POST', '/api/v1/documents/'.$documentId.'/trash');
        self::assertResponseIsSuccessful();
        self::assertSame(['cancelled', true, '2026-11-22T10:00:00+00:00'], [$this->at('data', 'status'), $this->at('data', 'trashed'), $this->at('data', 'trashed_at')]);
        self::assertNotNull($this->at('data', 'files', 'pdf'), 'The trash keeps its files.');
        self::assertNotNull($this->at('data', 'template', 'version_id'), 'The trash keeps its frozen template version.');
        $this->send('POST', '/api/v1/documents/'.$documentId.'/trash');
        self::assertResponseIsSuccessful();

        $this->get('/api/v1/documents');
        self::assertNotContains($documentId, $this->ids());
        $this->get('/api/v1/documents', ['trashed' => 'true']);
        self::assertSame([$documentId], $this->ids());
        $rows = $this->json()['data'];
        self::assertEquals(['items' => $rows], $this->in($this->call('foresi_list_documents', ['trashed' => true]), 'structuredContent', 'data'));
        $this->get('/api/v1/documents/'.$documentId);
        self::assertTrue($this->at('data', 'trashed'));

        $restored = $this->call('foresi_restore_document', ['document_id' => $documentId]);
        self::assertSame(['cancelled', false, null], [$this->in($restored, 'structuredContent', 'data', 'status'), $this->in($restored, 'structuredContent', 'data', 'trashed'), $this->in($restored, 'structuredContent', 'data', 'trashed_at')]);
        $this->call('foresi_trash_document', ['document_id' => $documentId]);
        $this->get('/api/v1/documents', ['trashed' => 'true']);
        self::assertSame([$documentId], $this->ids());
        self::assertCount(2, $this->events('document-templates.document.trashed'));
        self::assertCount(1, $this->events('document-templates.document.restored'));
    }

    /**
     * @return array{string, string} the ids of a ready document and its one signer
     */
    private function readyDocumentWithASigner(): array
    {
        $this->send('POST', '/api/v1/document-types', ['name' => 'Agreement', 'code' => 'agreement_'.substr(Id::generate(), -8)]);
        $typeId = $this->string('data', 'id');
        $this->send('POST', '/api/v1/document-templates', ['type_id' => $typeId, 'name' => 'Agreement', 'body' => self::BODY]);
        $templateId = $this->string('data', 'id');
        $this->send('POST', '/api/v1/documents', ['type_id' => $typeId, 'template_id' => $templateId, 'title' => 'Agreement']);
        $documentId = $this->string('data', 'id');
        $this->send('POST', '/api/v1/documents/'.$documentId.'/signers', ['name' => 'Jane Client', 'side' => 'theirs']);
        $signerId = $this->string('data', 'id');
        $this->send('POST', '/api/v1/documents/'.$documentId.'/generate');
        self::assertSame('ready', $this->at('data', 'status'));

        return [$documentId, $signerId];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null, ?string $secret = null, ?string $idempotencyKey = null): void
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $idempotencyKey) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }
        $this->client->request($method, $path, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function bytes(): string
    {
        return $this->client->getInternalResponse()->getContent();
    }

    private function string(string|int ...$keys): string
    {
        $value = $this->at(...$keys);
        self::assertIsString($value, json_encode($this->json(), \JSON_THROW_ON_ERROR));

        return $value;
    }

    /**
     * A write or read tool that succeeds.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function call(string $name, array $arguments): array
    {
        $result = $this->tool($this->writer, $name, $arguments);
        self::assertFalse($result['isError'] ?? false, $name.': '.json_encode($result, \JSON_THROW_ON_ERROR));

        return $result;
    }

    /**
     * @param array<mixed> $result
     */
    private function id(array $result): string
    {
        $id = $this->in($result, 'structuredContent', 'data', 'id');
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    private function tool(string $secret, string $name, array $arguments): array
    {
        $this->session = null;
        $this->mcp($secret, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']]);
        $result = $this->in($this->mcp($secret, 'tools/call', ['name' => $name, 'arguments' => [] === $arguments ? new \stdClass() : $arguments]), 'result');
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

    /**
     * @return list<mixed> the recorded events of this name
     */
    private function events(string $name): array
    {
        return $this->em()->getConnection()->fetchFirstColumn('SELECT '.EventLogTable::DATA.' FROM '.EventLogTable::LOG.' WHERE '.EventLogTable::NAME.' = ?', [$name]);
    }
}
