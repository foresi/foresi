<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\OAuth;

use Foresi\Identity\Application\OAuth\ClientMetadataDocuments;
use Foresi\Identity\Application\OAuth\OAuthClients;
use Foresi\Identity\Application\OAuth\OAuthRefused;
use Foresi\Identity\Domain\OAuth\OAuthClient;
use Foresi\Identity\Domain\OAuth\OAuthClientKind;
use Foresi\Tests\Identity\Support\InMemoryOAuthStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Who an OAuth client is (ADR 0036): a registered client, or one described by
 * its metadata document, and what a registration may ask for.
 */
final class OAuthClientsTest extends TestCase
{
    private const string URL = 'https://client.example.com/oauth/metadata.json';

    private InMemoryOAuthStore $store;
    private MockClock $clock;
    private int $fetches = 0;

    /** @var array<string, mixed> */
    private array $document = ['client_id' => self::URL, 'redirect_uris' => ['https://client.example.com/cb']];

    protected function setUp(): void
    {
        $this->store = new InMemoryOAuthStore();
        $this->clock = new MockClock('2026-11-18 10:00:00');
    }

    public function testARegisteredClientIsFoundByItsId(): void
    {
        $client = $this->clients()->register(['redirect_uris' => ['https://client.example.com/cb']]);

        self::assertSame($client, $this->clients()->resolve($client->clientId()));
        self::assertSame($client, $this->clients()->known($client->clientId()));
    }

    public function testAMissingOrUnknownClientIsShownToThePerson(): void
    {
        self::assertSame('The client_id is missing.', $this->refusedResolve('')->description);
        self::assertSame('The client_id is missing.', $this->refusedResolve(str_repeat('a', OAuthClient::CLIENT_ID_MAX_LENGTH + 1))->description);
        $unknown = $this->refusedResolve(str_repeat('a', OAuthClient::CLIENT_ID_MAX_LENGTH));
        self::assertSame('The client is not registered.', $unknown->description);
        self::assertSame(OAuthRefused::INVALID_CLIENT, $unknown->error);
        self::assertNull($unknown->redirectUri);
    }

    public function testTheTokenEndpointKnowsOnlyStoredClients(): void
    {
        foreach (['', 'foresi_client_unknown'] as $clientId) {
            try {
                $this->clients()->known($clientId);
                self::fail('Unknown client.');
            } catch (OAuthRefused $refused) {
                self::assertSame(OAuthRefused::INVALID_CLIENT, $refused->error);
            }
        }
    }

    public function testAMetadataDocumentIsReadOnceADay(): void
    {
        $client = $this->clients()->resolve(self::URL);
        self::assertSame(1, $this->fetches);
        self::assertSame(1, $this->store->flushes);
        self::assertSame('client.example.com', $client->name());
        self::assertSame(OAuthClientKind::Metadata, $client->kind());

        $this->clock->sleep(OAuthClients::METADATA_TTL_SECONDS - 1);
        self::assertSame($client, $this->clients()->resolve(self::URL));
        self::assertSame(1, $this->fetches);

        $this->document['client_name'] = 'Example Client';
        $this->document['redirect_uris'] = ['https://client.example.com/new'];
        $this->clock->sleep(1);
        self::assertSame($client, $this->clients()->resolve(self::URL));
        self::assertSame(2, $this->fetches);
        self::assertSame(2, $this->store->flushes);
        self::assertSame('Example Client', $client->name());
        self::assertSame(['https://client.example.com/new'], $client->redirectUris());
        self::assertCount(1, $this->store->clients);
    }

    public function testABlankNameInADocumentFallsBackToItsHost(): void
    {
        $this->document['client_name'] = '   ';

        self::assertSame('client.example.com', $this->clients()->resolve(self::URL)->name());
    }

    public function testRegistrationIssuesAPublicClientId(): void
    {
        $client = $this->clients()->register(['client_name' => 'Example', 'redirect_uris' => ['https://client.example.com/cb']]);

        self::assertMatchesRegularExpression('/^foresi_client_[0-9a-f]{32}$/', $client->clientId());
        self::assertSame(OAuthClientKind::Registered, $client->kind());
        self::assertSame('Example', $client->name());
        self::assertSame([$client], $this->store->clients);
        self::assertSame(1, $this->store->flushes);
    }

    public function testABlankNameFallsBackToTheFirstRedirectHost(): void
    {
        $client = $this->clients()->register(['client_name' => '  ', 'redirect_uris' => ['https://first.example.com/cb', 'https://second.example.com/cb']]);

        self::assertSame('first.example.com', $client->name());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function allowedRegistrations(): iterable
    {
        yield 'defaults' => [[]];
        yield 'code grant' => [['grant_types' => ['authorization_code']]];
        yield 'both grants' => [['grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']]];
        yield 'refresh only' => [['grant_types' => ['refresh_token']]];
        yield 'public' => [['token_endpoint_auth_method' => 'none']];
        yield 'ten redirects' => [['redirect_uris' => array_map(static fn (int $i): string => 'https://client.example.com/cb'.$i, range(1, OAuthClient::MAX_REDIRECT_URIS))]];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[DataProvider('allowedRegistrations')]
    public function testARegistrationWithinTheRulesIsAccepted(array $metadata): void
    {
        $client = $this->clients()->register([...['redirect_uris' => ['https://client.example.com/cb']], ...$metadata]);

        self::assertSame(OAuthClientKind::Registered, $client->kind());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedRegistrations(): iterable
    {
        yield 'implicit grant' => [['grant_types' => ['implicit']], OAuthRefused::INVALID_CLIENT_METADATA];
        yield 'grant types not a list' => [['grant_types' => 'authorization_code'], OAuthRefused::INVALID_CLIENT_METADATA];
        yield 'token response' => [['response_types' => ['token']], OAuthRefused::INVALID_CLIENT_METADATA];
        yield 'confidential' => [['token_endpoint_auth_method' => 'client_secret_basic'], OAuthRefused::INVALID_CLIENT_METADATA];
        yield 'no redirects' => [['redirect_uris' => []], OAuthRefused::INVALID_REDIRECT_URI];
        yield 'too many redirects' => [['redirect_uris' => array_map(static fn (int $i): string => 'https://client.example.com/cb'.$i, range(0, OAuthClient::MAX_REDIRECT_URIS))], OAuthRefused::INVALID_REDIRECT_URI];
        yield 'unsafe redirect' => [['redirect_uris' => ['javascript:alert(1)']], OAuthRefused::INVALID_REDIRECT_URI];
        yield 'redirect not a string' => [['redirect_uris' => [42]], OAuthRefused::INVALID_REDIRECT_URI];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[DataProvider('refusedRegistrations')]
    public function testARegistrationOutsideTheRulesIsRefused(array $metadata, string $error): void
    {
        try {
            $this->clients()->register([...['redirect_uris' => ['https://client.example.com/cb']], ...$metadata]);
            self::fail('The registration is refused.');
        } catch (OAuthRefused $refused) {
            self::assertSame($error, $refused->error);
        }
        self::assertSame([], $this->store->clients);
    }

    private function refusedResolve(string $clientId): OAuthRefused
    {
        try {
            $this->clients()->resolve($clientId);
        } catch (OAuthRefused $refused) {
            return $refused;
        }
        self::fail('The client is refused.');
    }

    private function clients(): OAuthClients
    {
        $test = $this;
        $documents = new readonly class($test) implements ClientMetadataDocuments {
            public function __construct(private OAuthClientsTest $test)
            {
            }

            public function fetch(string $url): array
            {
                return $this->test->fetched();
            }
        };

        return new OAuthClients($this->store, $documents, $this->clock);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetched(): array
    {
        ++$this->fetches;

        return $this->document;
    }
}
