<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Support;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\ApiToken;
use Foresi\Identity\Domain\OAuth\Pkce;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Application\Sealer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * An MCP client signing in over OAuth 2.1 (ADR 0036): the browser, the
 * registration, the consent screen, the token endpoint and /mcp, for the tests
 * of the flow here and in the integrations of Identity.
 */
abstract class OAuthTestCase extends WebTestCase
{
    protected const string MCP = 'http://localhost/mcp';
    protected const string REDIRECT = 'http://127.0.0.1/callback';
    protected const string VERIFIER = 'a-verifier-that-is-long-enough-for-pkce-0123456789';

    protected KernelBrowser $client;
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get('doctrine.orm.entity_manager');
        $fixtures = new IdentityFixtures($this->em);
        $fixtures->rebuildSchema();
        $fixtures->join($fixtures->user());
    }

    protected function register(): string
    {
        $this->client->request('POST', '/oauth/register', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['client_name' => 'Example Agent', 'redirect_uris' => [self::REDIRECT]]));
        self::assertResponseStatusCodeSame(201);
        $clientId = $this->decoded()['client_id'];
        self::assertIsString($clientId);

        return $clientId;
    }

    protected function approve(string $clientId): string
    {
        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId));
        $this->client->submit($crawler->filter('button[value="allow"]')->form());

        return $this->answer()['code'] ?? '';
    }

    protected function location(): string
    {
        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /**
     * @return array<string, string> the query of the redirect back to the client
     */
    protected function answer(): array
    {
        parse_str((string) parse_url($this->location(), \PHP_URL_QUERY), $query);
        $answer = [];
        foreach ($query as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $answer[$name] = $value;
            }
        }

        return $answer;
    }

    /**
     * @param array<string, string> $change
     */
    protected function query(string $clientId, array $change = []): string
    {
        return http_build_query($change + [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'state' => 'xyz',
            'code_challenge' => Pkce::challenge(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'resource' => self::MCP,
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array<string, string> the answer, numbers as text
     */
    protected function token(array $parameters): array
    {
        $this->client->request('POST', '/oauth/token', $parameters);
        $answer = [];
        foreach ($this->decoded() as $name => $value) {
            if (\is_string($value) || \is_int($value)) {
                $answer[$name] = (string) $value;
            }
        }

        return $answer;
    }

    /**
     * @param array<string, string> $parameters
     */
    protected function assertTokenError(string $error, array $parameters, int $status = 400): void
    {
        $answer = $this->token($parameters);
        self::assertResponseStatusCodeSame($status);
        self::assertSame($error, $answer['error'] ?? null, $answer['error_description'] ?? '');
    }

    protected function mcp(string $accessToken): int
    {
        $this->client->request('POST', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], content: '{"jsonrpc":"2.0","id":1,"method":"ping"}');

        return \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED === $this->client->getResponse()->getStatusCode() ? 401 : 200;
    }

    protected function connection(): ApiToken
    {
        $this->em->clear();
        $tokens = $this->em->getRepository(ApiToken::class)->findAll();
        self::assertCount(1, $tokens);
        self::assertNotNull($tokens[0]->oauthClientId());

        return $tokens[0];
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(string $method, string $path): array
    {
        $this->client->request($method, $path);
        self::assertResponseIsSuccessful();

        return $this->decoded();
    }

    /**
     * @return array<string, mixed>
     */
    protected function decoded(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $members = [];
        foreach ($decoded as $name => $value) {
            if (\is_string($name)) {
                $members[$name] = $value;
            }
        }

        return $members;
    }

    protected function signIn(bool $twoFactor = true, string $email = IdentityFixtures::EMAIL): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->filter('form button[type="submit"]')->form(['_username' => $email, '_password' => IdentityFixtures::PASSWORD]));
        if ($twoFactor) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            self::assertInstanceOf(User::class, $user);
            $user->enrolTwoFactor(self::getContainer()->get(Sealer::class)->seal('JBSWY3DPEHPK3PXP'), ['recovery-hash']);
            self::getContainer()->get(UserRepository::class)->save($user);
        }
    }

    /**
     * @return list<string> the form-action sources of the last response
     */
    protected function formAction(): array
    {
        $policy = (string) $this->client->getResponse()->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match('/(?:^|; )form-action ([^;]+)/', $policy, $match), $policy);

        return explode(' ', $match[1]);
    }
}
