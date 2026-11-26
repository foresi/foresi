<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Integration\Work;

use Foresi\Identity\Domain\Role;
use Foresi\Tests\Identity\Support\IdentityFixtures;
use Foresi\Tests\Identity\Support\OAuthTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A guest connects an agent over OAuth with only what a guest may hold: the
 * scopes Work opens to guests (ADR 0041).
 */
final class GuestOAuthFlowTest extends OAuthTestCase
{
    public function testAGuestConnectsTheirAgentWithOnlyWhatAGuestMayHold(): void
    {
        $fixtures = new IdentityFixtures($this->em);
        $fixtures->join($fixtures->user('guest@example.com'), Role::Guest);
        $clientId = $this->register();
        $this->signIn(email: 'guest@example.com');

        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId, ['scope' => 'read write contacts:write']));
        self::assertResponseIsSuccessful();
        $offered = $crawler->filter('input[name="scopes[]"]')->each(static fn (Crawler $box): string => (string) $box->attr('value'));
        // The modules in the order of their names in the person's language: Práce (Work) before Profil in Czech.
        self::assertSame(['projects:read', 'tasks:read', 'tasks:write', 'profile:read', 'profile:write'], $offered);
        // What a guest reads stays ticked; writing tasks can be unticked.
        self::assertNotNull($crawler->filter('#scope-tasks-read')->attr('disabled'));
        self::assertNotNull($crawler->filter('#scope-tasks-write')->attr('checked'));
        // The same layout as for staff: who signs in, then the access by module, without the whole account.
        self::assertSelectorTextContains('main', 'guest@example.com · guest');
        self::assertCount(0, $crawler->filter('#scope-read, details'));
        self::assertCount(1, $crawler->filterXPath('//*[@role="group"][p[normalize-space()="Work"]]'));
        self::assertSame(["'self'", 'http://127.0.0.1'], $this->formAction());

        $this->client->submit($crawler->filter('button[value="allow"]')->form());
        $answer = $this->answer();
        $tokens = $this->token(['grant_type' => 'authorization_code', 'code' => $answer['code'] ?? '', 'redirect_uri' => self::REDIRECT, 'client_id' => $clientId, 'code_verifier' => self::VERIFIER, 'resource' => self::MCP]);
        self::assertResponseIsSuccessful();
        self::assertSame('profile:write projects:read tasks:write', $tokens['scope']);
        self::assertSame(200, $this->mcp($tokens['access_token']));

        // A refresh cannot widen what the guest gave.
        $this->assertTokenError('invalid_scope', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId, 'scope' => 'contacts:read']);

        // The guest sees and disconnects the agent on their own page.
        $crawler = $this->client->request('GET', '/connected-apps');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-api-connections="mine"]', 'Example Agent');
        self::assertSelectorTextContains('[data-mcp-server]', self::MCP);
        $this->client->submit($crawler->filter('[data-api-connections="mine"] form')->form());
        self::assertResponseRedirects('/connected-apps');
        self::assertSame(401, $this->mcp($tokens['access_token']));
    }
}
