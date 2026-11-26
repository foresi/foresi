<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Integration\Inbox;

use Foresi\Work\Application\ClientProjects;
use Foresi\Work\Application\ProjectRow;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Domain\WorkflowTemplate;
use PHPUnit\Framework\TestCase;

/**
 * The projects an inbox item offers: the company's when the item has one, else the person's,
 * and every project when the client has none.
 */
final class ClientProjectsTest extends TestCase
{
    private const string PERSON = '0192f0a0-1111-7000-8000-000000000001';
    private const string COMPANY = '0192f0a0-1111-7000-8000-000000000002';

    public function testTheCompanyOfAnItemOutranksThePersonAndTheListHasNoGaps(): void
    {
        $projects = $this->projects();

        self::assertSame(['p-company-1', 'p-company-2'], array_map(static fn (ProjectRow $project): string => $project->id, $projects->of(self::PERSON, self::COMPANY)));
        self::assertSame([0, 1], array_keys($projects->of(self::PERSON, self::COMPANY)), 'A list, not the rows of the whole filter.');
    }

    public function testAnItemWithoutACompanyOffersTheProjectsOfThePerson(): void
    {
        self::assertSame(['p-person'], array_map(static fn (ProjectRow $project): string => $project->id, $this->projects()->of(self::PERSON, null)));
    }

    public function testAnItemWithoutAClientOffersNoClientProjectsNotTheOnesWithoutAClient(): void
    {
        self::assertSame([], $this->projects()->of(null, null));
    }

    public function testTheChoicesAreTheProjectsOfTheClientByName(): void
    {
        self::assertSame(['p-company-1' => 'Roof', 'p-company-2' => 'Windows'], $this->projects()->choices(self::PERSON, self::COMPANY));
        self::assertSame(['p-person' => 'Garden'], $this->projects()->choices(self::PERSON, null));
    }

    public function testAClientWithoutProjectsOrNoClientOffersEveryProject(): void
    {
        $all = ['p-none' => 'Internal', 'p-company-1' => 'Roof', 'p-person' => 'Garden', 'p-company-2' => 'Windows'];

        self::assertSame($all, $this->projects()->choices(null, null));
        self::assertSame($all, $this->projects()->choices('0192f0a0-1111-7000-8000-000000000003', null));
    }

    private function projects(): ClientProjects
    {
        $queries = self::createStub(WorkQueries::class);
        $queries->method('projects')->willReturn([
            $this->row('p-none', 'Internal', null),
            $this->row('p-company-1', 'Roof', self::COMPANY),
            $this->row('p-person', 'Garden', self::PERSON),
            $this->row('p-company-2', 'Windows', self::COMPANY),
        ]);

        return new ClientProjects($queries);
    }

    private function row(string $id, string $name, ?string $clientId): ProjectRow
    {
        return new ProjectRow($id, $name, $clientId, null, WorkflowTemplate::Simple, 0, null, false);
    }
}
