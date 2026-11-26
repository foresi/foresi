<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Activities;

use Foresi\Activities\Domain\ActivityTiming;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Tests\Shared\Infrastructure\Api\ApiTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A project as the record of an activity: an e-mail logged over MCP with a
 * link to the project shows on the project's Activities tab, after List and
 * Board, which stay the default; an archived project takes no new activity.
 */
final class ProjectActivitiesTest extends ApiTestCase
{
    private string $writer;
    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        // The migration seeds the types of an instance; the test database is made from the mapping.
        $this->flushed(fn () => $this->em()->persist(new ActivityType('email', null, ActivityTiming::None, null, new \DateTimeImmutable())));
        $this->writer = $this->issued([ApiScope::from('activities:write'), ApiScope::from('projects:write')]);
    }

    public function testAnActivityLinkedToAProjectShowsOnTheProjectTab(): void
    {
        $project = $this->id($this->call('foresi_create_project', ['name' => 'Roof audit']));
        $types = $this->in($this->call('foresi_list_activity_types', []), 'structuredContent', 'data', 'items');
        self::assertIsArray($types);
        $email = array_values(array_filter($types, static fn (mixed $type): bool => \is_array($type) && 'email' === ($type['key'] ?? null)))[0] ?? null;
        self::assertIsArray($email);
        self::assertSame('E-mail', $email['name']);
        $this->call('foresi_create_activity', [
            'type_id' => $email['id'],
            'subject' => 'Re: Roof audit',
            'body' => "From: Client A <client.a@example.com>\n\nThe audit can start on Monday.",
            'links' => [['kind' => 'project', 'id' => $project]],
        ]);

        $this->client->loginUser(new SecurityUser($this->user->id(), $this->user->email(), $this->user->passwordHash()), 'main');
        $page = '/projects/'.$project;
        $crawler = $this->client->request('GET', $page);
        self::assertResponseIsSuccessful();
        // Activities follows List and Board, before the budget; the list stays the tab shown by default.
        self::assertSame([$page, $page.'/board', $page.'?tab=activities', $page.'?tab=budget'], $crawler->filter('[data-project-tabs] a')->each(static fn (Crawler $tab): string => (string) $tab->attr('href')));
        self::assertSame(['List'], $crawler->filter('[data-project-tabs] a[aria-current="page"]')->each(static fn (Crawler $tab): string => trim($tab->text())));
        self::assertCount(0, $crawler->filter('[data-project-section]'));

        $crawler = $this->client->request('GET', $page.'?tab=activities');
        self::assertResponseIsSuccessful();
        self::assertSame(['Activities'], $crawler->filter('[data-project-tabs] a[aria-current="page"]')->each(static fn (Crawler $tab): string => trim($tab->text())));
        self::assertCount(0, $crawler->filter('[data-task-filters]'), 'the section takes the place of the tasks');
        $frame = $crawler->filter('[data-project-section="activities"] turbo-frame#project-section-activities');
        self::assertSame('/activities/for/project/'.$project.'?return=/projects/'.$project.'?tab%3Dactivities', $frame->attr('src'));

        $crawler = $this->client->request('GET', $frame->attr('src'), server: ['HTTP_TURBO_FRAME' => 'project-section-activities']);
        self::assertResponseIsSuccessful();
        $item = $crawler->filter('turbo-frame#project-section-activities [data-activities-done] [data-activity]');
        self::assertCount(1, $item);
        self::assertSame('Re: Roof audit', trim($item->filter('[data-activity-subject]')->text()));
        self::assertSame('E-mail', trim($item->filter('[data-activity-type]')->text()));
        // The sender line opens the body, shown as text.
        self::assertSame('From: Client A <client.a@example.com>', trim($item->filter('[data-activity-sender]')->text()));
        self::assertStringContainsString('&lt;client.a@example.com&gt;', $item->html());
    }

    public function testAnArchivedProjectTakesNoNewActivity(): void
    {
        $project = $this->id($this->call('foresi_create_project', ['name' => 'Old roof']));
        $this->call('foresi_archive_project', ['project_id' => $project]);
        $types = $this->in($this->call('foresi_list_activity_types', []), 'structuredContent', 'data', 'items');
        self::assertIsArray($types);
        self::assertIsArray($types[0]);

        $result = $this->tool($this->writer, 'foresi_create_activity', ['type_id' => $types[0]['id'], 'subject' => 'Late note', 'links' => [['kind' => 'project', 'id' => $project]]]);

        self::assertTrue($result['isError'] ?? false);
        self::assertStringContainsString('link_target_unknown', json_encode($result, \JSON_THROW_ON_ERROR));
    }

    /**
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
}
