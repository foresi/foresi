<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity;

use Foresi\Identity\Application\GuestScopes;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Domain\User;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Foresi\Ui\UiBundle;

/**
 * A person's own name, language and appearance over REST and MCP, and what /api/v1/me tells about them.
 */
final class ProfileApiTest extends ApiTestCase
{
    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = $this->issued([ApiScope::from('profile:write')]);
    }

    public function testMeTellsTheNameTheLanguageAndThePhoto(): void
    {
        $this->get('/api/v1/me', secret: $this->secret);

        self::assertResponseIsSuccessful();
        self::assertSame(['id', 'email', 'display_name', 'language', 'text_size', 'density', 'photo_url'], array_keys((array) $this->at('user')));
        self::assertSame(['standard', 'normal'], [$this->at('user', 'text_size'), $this->at('user', 'density')], 'Everyone starts on the standard text and the normal density.');
        self::assertSame($this->user->displayName(), $this->at('user', 'display_name'));
        self::assertSame($this->user->locale(), $this->at('user', 'language'));
        self::assertNull($this->at('user', 'photo_url'));

        $this->flushed(fn () => $this->user->changePhoto());
        $this->get('/api/v1/me', secret: $this->secret);
        // The photo is served by the profile screens, so without Ui it has no address (ADR 0038).
        self::assertSame(class_exists(UiBundle::class) ? 'http://localhost/people/'.$this->user->id().'/photo?v='.$this->user->photoVersion() : null, $this->at('user', 'photo_url'));
    }

    public function testANameAloneKeepsTheLanguage(): void
    {
        $language = $this->user->locale();

        $this->send('PATCH', '/api/v1/me', ['display_name' => '  Jane Example ']);

        self::assertResponseIsSuccessful();
        self::assertSame(['Jane Example', $language], [$this->at('data', 'display_name'), $this->at('data', 'language')]);
        self::assertSame('Jane Example', $this->stored()->displayName());
    }

    public function testTheLanguageChanges(): void
    {
        $this->send('PATCH', '/api/v1/me', ['language' => 'cs']);
        self::assertResponseIsSuccessful();
        self::assertSame('cs', $this->stored()->locale());

        $this->send('PATCH', '/api/v1/me', ['language' => 'de']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('cs', $this->stored()->locale());
    }

    public function testTheAppearanceChangesAloneAndKeepsTheRest(): void
    {
        $name = $this->user->displayName();

        $this->send('PATCH', '/api/v1/me', ['text_size' => 'large', 'density' => 'compact']);

        self::assertResponseIsSuccessful();
        self::assertSame(['large', 'compact', $name], [$this->at('data', 'text_size'), $this->at('data', 'density'), $this->at('data', 'display_name')]);
        self::assertSame(['large', 'compact'], [$this->stored()->textSize(), $this->stored()->density()]);

        $this->send('PATCH', '/api/v1/me', ['density' => 'spacious']);
        self::assertResponseIsSuccessful();
        self::assertSame(['large', 'spacious'], [$this->stored()->textSize(), $this->stored()->density()]);
    }

    public function testAnUnknownTextSizeOrDensityIsRefused(): void
    {
        $this->send('PATCH', '/api/v1/me', ['text_size' => 'huge']);
        self::assertResponseStatusCodeSame(400);
        $this->send('PATCH', '/api/v1/me', ['density' => 'comfortable']);
        self::assertResponseStatusCodeSame(400);

        self::assertSame(['standard', 'normal'], [$this->stored()->textSize(), $this->stored()->density()]);
    }

    public function testABlankNameIsRefused(): void
    {
        $this->send('PATCH', '/api/v1/me', ['display_name' => '   ']);

        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'display_name', 'reason' => 'name_required']);
    }

    public function testATokenWithoutTheProfileScopeIsRefused(): void
    {
        $this->writer = $this->issued([ApiScope::from('firm:write')]);

        $this->send('PATCH', '/api/v1/me', ['display_name' => 'Jane Example']);

        $this->assertProblem(403, 'insufficient_scope');
    }

    public function testAGuestMayHoldTheProfileScope(): void
    {
        $offered = array_map(static fn (ApiScope $scope): string => $scope->value, self::getContainer()->get(GuestScopes::class)->offered());

        self::assertContains('profile:write', $offered);
    }

    public function testTheSameOverMcp(): void
    {
        $result = $this->tool('foresi_update_profile', ['display_name' => 'Jane Example', 'language' => 'cs']);

        self::assertFalse($result['isError'] ?? false);
        self::assertSame(['Jane Example', 'cs'], [$this->stored()->displayName(), $this->stored()->locale()]);

        $whoami = $this->tool('foresi_whoami', []);
        $structured = $whoami['structuredContent'] ?? null;
        self::assertIsArray($structured);
        $data = $structured['data'] ?? null;
        self::assertIsArray($data);
        $user = $data['user'] ?? null;
        self::assertIsArray($user);
        self::assertSame('Jane Example', $user['display_name'] ?? null);

        $result = $this->tool('foresi_update_profile', ['text_size' => 'small', 'density' => 'spacious']);
        self::assertFalse($result['isError'] ?? false);
        self::assertSame(['small', 'spacious', 'Jane Example'], [$this->stored()->textSize(), $this->stored()->density(), $this->stored()->displayName()]);
    }

    private function stored(): User
    {
        $this->em()->clear();
        $user = $this->em()->find(User::class, $this->user->id());
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body): void
    {
        $this->client->request($method, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->writer, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => bin2hex(random_bytes(8))], content: json_encode($body, \JSON_THROW_ON_ERROR));
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
        $arguments += 'foresi_whoami' === $name ? [] : ['idempotency_key' => bin2hex(random_bytes(8))];
        $body = $this->mcp('tools/call', ['name' => $name, 'arguments' => $arguments]);
        self::assertIsArray($body['result'] ?? null);

        return $body['result'];
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
}
