<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Support;

use Foresi\Identity\Application\ApiTokens;
use Foresi\Identity\Application\OAuth\OAuthStore;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\ApiToken;
use Foresi\Identity\Domain\ApiTokenSecret;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\OAuthClientKind;
use Foresi\Identity\Domain\Role;
use Foresi\Purchasing\Application\Reading\ReadReceivedDocument;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\Reading;
use Foresi\Purchasing\Contract\Reading\ReadValue;
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Purchasing\Domain\DocumentSource;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentContent;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The API tests of the reading and the upload of received documents: a token
 * that writes them, drafts made straight in the store, one call at a time over
 * REST and over MCP, and what the last request queued.
 */
abstract class ReceivedDocumentApiTestCase extends ApiTestCase
{
    protected string $writer;
    protected ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('received_documents:write')]);
    }

    /**
     * A draft in the store as an upload makes it, with an original file by default.
     *
     * @param array<string, array{string|int|array<mixed>, string}> $found   field => [value, source] the reading found
     * @param list<ReadField>                                       $applied
     */
    protected function draft(DocumentSource $source = DocumentSource::Upload, bool $file = true, array $found = [], array $applied = [], ?string $failure = null, ?string $mail = null): string
    {
        $now = \DateTimeImmutable::createFromInterface(self::getContainer()->get(ClockInterface::class)->now());
        $document = new ReceivedDocument(ReceivedDocumentContent::blank($now), $this->user->id(), $now, $source, $mail);
        if ($file) {
            $document->attachOriginal(Id::generate());
        }
        $reading = new DocumentReading($document, $document->version(), $now);
        if (null !== $failure) {
            $reading->fail($failure, $now);
        } elseif ([] !== $found) {
            $values = [];
            foreach ($found as $field => [$value, $from]) {
                $values[] = new ReadValue(ReadField::from($field), $value, $from);
            }
            $merged = new Reading($values);
            $suggested = array_values(array_filter(array_map(static fn (ReadValue $value): ReadField => $value->field, $values), static fn (ReadField $field): bool => !\in_array($field, $applied, true)));
            $reading->finish($merged, $applied, $suggested, [], $now);
        }
        $this->flushed(function () use ($document, $reading): void {
            $this->em()->persist($document);
            $this->em()->persist($reading);
        });

        return $document->id();
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function send(string $method, string $path, ?array $body = null, ?string $secret = null): void
    {
        $this->client->request($method, $path, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: null === $body ? null : json_encode((object) $body, \JSON_THROW_ON_ERROR));
    }

    /**
     * The readings the last request queued: the test client resets the transport with the next request.
     *
     * @return list<ReadReceivedDocument>
     */
    protected function queued(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof ReadReceivedDocument) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * The token of an agent a guest connected: it holds nothing of Purchasing.
     */
    protected function guestSecret(): string
    {
        $guest = $this->fixtures->user('guest@example.com');
        $this->fixtures->join($guest, Role::Guest);

        return $this->flushed(function () use ($guest): string {
            $now = self::getContainer()->get(ClockInterface::class)->now();
            $client = new OAuthClient('foresi_client_'.bin2hex(random_bytes(8)), OAuthClientKind::Registered, 'Example Agent', ['https://agent.example.com/callback'], $now);
            self::getContainer()->get(OAuthStore::class)->addClient($client);
            $secret = ApiTokenSecret::generateOAuth();
            self::getContainer()->get(ApiTokens::class)->add(new ApiToken($guest->id(), 'Example Agent', [ApiScope::write()], ApiTokenSecret::hash($secret), ApiTokenSecret::lastFour($secret), $now, $now->modify('+1 hour'), $client->id()));

            return $secret;
        });
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<mixed>
     */
    protected function tool(string $name, array $arguments, ?string $secret = null): array
    {
        $this->session = null;
        $this->mcp('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']], $secret);
        $result = $this->in($this->mcp('tools/call', ['name' => $name, 'arguments' => [] === $arguments ? new \stdClass() : $arguments], $secret), 'result');
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @return list<string> the names of the tools the token sees
     */
    protected function toolNames(string $secret): array
    {
        $this->session = null;
        $this->mcp('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1.0']], $secret);
        $tools = $this->in($this->mcp('tools/list', [], $secret), 'result', 'tools');
        self::assertIsArray($tools);

        return array_values(array_filter(array_column($tools, 'name'), \is_string(...)));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<mixed>
     */
    protected function mcp(string $method, array $params = [], ?string $secret = null): array
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.($secret ?? $this->writer), 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'];
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

    protected function in(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
