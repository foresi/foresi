<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\OAuth;

use Foresi\Identity\Application\OAuth\ClientMetadataDocuments;
use Foresi\Identity\Application\OAuth\OAuthClients;
use Foresi\Identity\Application\OAuth\OAuthRefused;
use Foresi\Identity\Application\OAuth\OAuthStore;
use Foresi\Identity\Domain\OAuth\AuthorizationCode;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\OAuthClientKind;
use Foresi\Identity\Domain\OAuth\RefreshToken;
use Foresi\Identity\Infrastructure\OAuth\HttpClientMetadataDocuments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Client ID metadata documents (ADR 0036): which URLs count, what the document
 * must say, how long a copy is trusted, and what the fetch refuses.
 */
final class ClientMetadataDocumentTest extends TestCase
{
    private const string URL = 'https://claude.ai/oauth/mcp-oauth-client-metadata';

    /** @var array<string, mixed> */
    private array $document = ['client_id' => self::URL, 'client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback', 'http://localhost/callback'], 'token_endpoint_auth_method' => 'none'];
    private int $fetches = 0;
    private MockClock $clock;
    private OAuthStore $store;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-18 10:00:00');
        $this->store = new class implements OAuthStore {
            /** @var array<string, OAuthClient> */
            public array $clients = [];

            public function addClient(OAuthClient $client): void
            {
                $this->clients[$client->clientId()] = $client;
            }

            public function clientByClientId(string $clientId): ?OAuthClient
            {
                return $this->clients[$clientId] ?? null;
            }

            public function client(string $id): ?OAuthClient
            {
                return null;
            }

            public function addCode(AuthorizationCode $code): void
            {
            }

            public function codeByHash(string $codeHash): ?AuthorizationCode
            {
                return null;
            }

            public function claimCode(string $id, \DateTimeImmutable $now): bool
            {
                return false;
            }

            public function attachToken(string $codeId, string $apiTokenId): void
            {
            }

            public function addRefreshToken(RefreshToken $token): void
            {
            }

            public function refreshTokenByHash(string $tokenHash): ?RefreshToken
            {
                return null;
            }

            public function claimRefreshToken(string $id, \DateTimeImmutable $now): bool
            {
                return false;
            }

            public function flush(): void
            {
            }
        };
    }

    public function testADocumentIsReadCheckedAndKeptForADay(): void
    {
        $client = $this->clients()->resolve(self::URL);

        self::assertSame(OAuthClientKind::Metadata, $client->kind());
        self::assertSame('Claude', $client->name());
        self::assertSame('claude.ai', $client->verifiedHost());
        self::assertTrue($client->allowsRedirect('http://localhost:49152/callback'));
        self::assertFalse($client->allowsRedirect('https://claude.ai/other'));

        $this->clock->modify('+23 hours');
        $this->document['client_name'] = 'Claude (renamed)';
        self::assertSame('Claude', $this->clients()->resolve(self::URL)->name());
        self::assertSame(1, $this->fetches);

        $this->clock->modify('+2 hours');
        self::assertSame('Claude (renamed)', $this->clients()->resolve(self::URL)->name());
        self::assertSame(2, $this->fetches);
        self::assertSame($client, $this->store->clientByClientId(self::URL));
    }

    public function testWithoutANameTheHostNamesTheClient(): void
    {
        unset($this->document['client_name']);

        self::assertSame('claude.ai', $this->clients()->resolve(self::URL)->name());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badUrls(): iterable
    {
        yield 'http' => ['http://claude.ai/oauth/metadata'];
        yield 'no path' => ['https://claude.ai'];
        yield 'root path' => ['https://claude.ai/'];
        yield 'dot segment' => ['https://claude.ai/oauth/../metadata'];
        yield 'fragment' => ['https://claude.ai/oauth/metadata#x'];
        yield 'credentials' => ['https://user@claude.ai/oauth/metadata'];
    }

    #[DataProvider('badUrls')]
    public function testOnlyAnHttpsUrlWithAPathIsAClientId(string $url): void
    {
        self::assertFalse(OAuthClients::isMetadataUrl($url));
        if (str_starts_with($url, 'https://')) {
            $this->document['client_id'] = $url;
            $this->assertRefused($url);
            self::assertSame(0, $this->fetches);
        }
    }

    public function testAnUnregisteredClientIdIsRefused(): void
    {
        $this->assertRefused('foresi_client_unknown');
        $this->assertRefused('');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badDocuments(): iterable
    {
        yield 'another client_id' => [['client_id' => 'https://evil.example.com/metadata']];
        yield 'a secret' => [['client_secret' => 'x']];
        yield 'secret authentication' => [['token_endpoint_auth_method' => 'client_secret_basic']];
        yield 'no redirect' => [['redirect_uris' => []]];
        yield 'an unsafe redirect' => [['redirect_uris' => ['http://example.com/cb']]];
        yield 'a redirect that is no string' => [['redirect_uris' => [42]]];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[DataProvider('badDocuments')]
    public function testADocumentMustDescribeAPublicClientWithSafeRedirects(array $change): void
    {
        $this->document = $change + $this->document;

        $this->assertRefused(self::URL);
    }

    public function testTheFetchRefusesPrivateAddressesRedirectsErrorsAndLargeDocuments(): void
    {
        $fetch = static fn (MockResponse $response, string $url = self::URL): array => new HttpClientMetadataDocuments(new MockHttpClient($response))->fetch($url);

        self::assertSame(['client_id' => self::URL], $fetch(new MockResponse('{"client_id":"'.self::URL.'"}', ['primary_ip' => '160.79.104.10'])));
        foreach ([
            'private address' => [new MockResponse('{}'), 'https://10.0.0.1/metadata'],
            'loopback' => [new MockResponse('{}'), 'https://127.0.0.1/metadata'],
            'link-local' => [new MockResponse('{}'), 'https://169.254.169.254/latest/meta-data'],
            'http' => [new MockResponse('{}'), 'http://claude.ai/metadata'],
            'redirect' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location' => 'https://claude.ai/x']]), self::URL],
            'error' => [new MockResponse('', ['http_code' => 404]), self::URL],
            'too large' => [new MockResponse('{"a":"'.str_repeat('x', HttpClientMetadataDocuments::MAX_BYTES).'"}'), self::URL],
            'not json' => [new MockResponse('<html>'), self::URL],
            'a list' => [new MockResponse('[1,2]'), self::URL],
        ] as $case => [$response, $url]) {
            try {
                $fetch($response, $url);
                self::fail($case.' was read.');
            } catch (OAuthRefused $refused) {
                self::assertSame(OAuthRefused::INVALID_CLIENT, $refused->error, $case);
                self::assertNull($refused->redirectUri, $case);
            }
        }
    }

    private function assertRefused(string $clientId): void
    {
        try {
            $this->clients()->resolve($clientId);
            self::fail('The client was accepted.');
        } catch (OAuthRefused $refused) {
            self::assertSame(OAuthRefused::INVALID_CLIENT, $refused->error);
            self::assertNull($refused->redirectUri);
        }
    }

    private function clients(): OAuthClients
    {
        $documents = new readonly class($this) implements ClientMetadataDocuments {
            public function __construct(private ClientMetadataDocumentTest $test)
            {
            }

            public function fetch(string $url): array
            {
                return $this->test->served();
            }
        };

        return new OAuthClients($this->store, $documents, $this->clock);
    }

    /**
     * @internal the document the stub serves
     *
     * @return array<string, mixed>
     */
    public function served(): array
    {
        ++$this->fetches;

        return $this->document;
    }
}
