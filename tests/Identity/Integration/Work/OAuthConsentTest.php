<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Identity\Integration\Work;

use Foresi\Tests\Identity\Support\OAuthTestCase;

/**
 * The consent screen shows a module with several resources, Work, under a heading of
 * its own, each resource with reading and changing.
 */
final class OAuthConsentTest extends OAuthTestCase
{
    public function testAModuleWithSeveralResourcesIsAGroupWithItsHeading(): void
    {
        $clientId = $this->register();
        $this->signIn();

        $crawler = $this->client->request('GET', '/oauth/authorize?'.$this->query($clientId, ['scope' => 'read projects:write']));
        self::assertResponseIsSuccessful();
        $work = $crawler->filterXPath('//*[@role="group"][p[normalize-space()="Work"]]');
        self::assertCount(1, $work);
        self::assertCount(1, $work->filter('#scope-projects-read'));
        self::assertNotNull($work->filter('#scope-projects-write')->attr('checked'));
        self::assertNull($work->filter('#scope-tasks-write')->attr('checked'));
    }
}
