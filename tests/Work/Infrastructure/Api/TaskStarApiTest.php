<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Api;

/**
 * My priorities over REST and MCP (plan 37.2): star, unstar and reorder as the person of
 * the token, the shortlist read with starred and sort my_order, and the refusals alike.
 */
final class TaskStarApiTest extends WorkApiTestCase
{
    public function testTasksAreStarredReorderedAndUnstarred(): void
    {
        $project = $this->project();
        $brief = $this->task($project, 'Write the brief');
        $call = $this->task($project, 'Call the client');
        $plan = $this->task($project, 'Plan the launch');

        $this->send('PUT', '/api/v1/tasks/'.$plan.'/star');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->at('data', 'starred'));
        $this->send('PUT', '/api/v1/tasks/'.$brief.'/star');
        $this->send('PUT', '/api/v1/tasks/'.$call.'/star', ['before_task_id' => $plan]);
        self::assertSame(['Call the client', 'Plan the launch', 'Write the brief'], $this->priorities());

        $this->send('POST', '/api/v1/tasks/starred/reorder', ['task_ids' => [$brief, $plan, $call]]);
        self::assertResponseIsSuccessful();
        self::assertSame([$brief, $plan, $call], $this->at('data', 'task_ids'));
        self::assertSame(['Write the brief', 'Plan the launch', 'Call the client'], $this->priorities());

        $this->send('POST', '/api/v1/tasks/starred/reorder', ['task_ids' => [$brief]]);
        $this->assertProblem(400, 'invalid_parameter', ['parameter' => 'task_ids']);

        $this->send('DELETE', '/api/v1/tasks/'.$plan.'/star');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->at('data', 'starred'));
        self::assertSame(['Write the brief', 'Call the client'], $this->priorities());
    }

    public function testMcpStarsAndListsMyPrioritiesAsRestDoes(): void
    {
        $project = $this->project();
        $brief = $this->task($project, 'Write the brief');
        $call = $this->task($project, 'Call the client');

        foreach ([$call, $brief] as $id) {
            $result = $this->mcpTool('foresi_star_task', ['task_id' => $id]);
            self::assertFalse($result['isError'] ?? false);
        }
        $result = $this->mcpTool('foresi_reorder_starred_tasks', ['task_ids' => [$brief, $call]]);
        self::assertFalse($result['isError'] ?? false);
        $listed = $this->mcpTool('foresi_search_tasks', ['starred' => true, 'sort' => 'my_order']);
        $items = self::in($listed, 'structuredContent', 'data', 'items');
        self::assertIsArray($items);
        self::assertSame(['Write the brief', 'Call the client'], array_column($items, 'title'));

        $result = $this->mcpTool('foresi_unstar_task', ['task_id' => $brief]);
        self::assertFalse($result['isError'] ?? false);
        self::assertSame(['Call the client'], $this->priorities());
    }

    public function testAFinishedTaskIsNotStarred(): void
    {
        $project = $this->project();
        $id = $this->task($project, 'Old bug');
        $this->send('PATCH', '/api/v1/tasks/'.$id, ['status_id' => $this->statuses($project)[2]->id()]);

        $this->send('PUT', '/api/v1/tasks/'.$id.'/star');

        $this->assertProblem(409, 'star_finished');
    }

    /**
     * @return list<string>
     */
    private function priorities(): array
    {
        $this->get('/api/v1/tasks', ['starred' => 'true', 'sort' => 'my_order'], $this->writer);
        self::assertResponseIsSuccessful();

        return array_values(array_filter($this->column('title', 'data'), \is_string(...)));
    }
}
