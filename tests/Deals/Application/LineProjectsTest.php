<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Deals\Application;

use Foresi\Deals\Application\LineProject;
use Foresi\Deals\Application\LineProjects;
use Foresi\Deals\Application\LineProjectSource;
use Foresi\Deals\Application\LineProjectTime;
use PHPUnit\Framework\TestCase;

/**
 * The projects of hourly lines as Sales reads them: once per request, and
 * without the time of a project the person does not see.
 */
final class LineProjectsTest extends TestCase
{
    private const string SEEN = '01900000-0000-7000-8000-000000000001';
    private const string HIDDEN = '01900000-0000-7000-8000-000000000002';

    public function testAProjectAndItsTimeAreReadOncePerRequest(): void
    {
        $source = $this->source();
        $time = $this->time();
        $projects = new LineProjects([$source], [$time]);

        foreach ([1, 2, 3] as $ignored) {
            self::assertSame('DUD', $projects->find(self::SEEN)?->name);
            self::assertSame(90, $projects->billableMinutes(self::SEEN));
        }
        self::assertSame([1, 1], [$source->calls['find'], $time->calls]);

        $projects->reset();
        $projects->billableMinutes(self::SEEN);
        self::assertSame([2, 2], [$source->calls['find'], $time->calls], 'a new request reads again');
    }

    public function testAProjectThePersonDoesNotSeeHasNoLoggedTime(): void
    {
        $time = $this->time();
        $projects = new LineProjects([$this->source()], [$time]);

        self::assertNull($projects->billableMinutes(self::HIDDEN));
        self::assertSame(0, $time->calls, 'its time is not read');
    }

    public function testWithoutTimesheetAProjectHasNoLoggedTime(): void
    {
        $projects = new LineProjects([$this->source()]);

        self::assertSame('DUD', $projects->find(self::SEEN)?->name);
        self::assertNull($projects->billableMinutes(self::SEEN));
    }

    public function testWithoutWorkThereAreNoProjects(): void
    {
        $projects = new LineProjects();

        self::assertFalse($projects->installed());
        self::assertNull($projects->find(self::SEEN));
        self::assertNull($projects->billableMinutes(self::SEEN));
        self::assertSame([], $projects->ofClient(self::SEEN));
    }

    /**
     * @return LineProjectSource&object{calls: array{find: int}}
     */
    private function source(): LineProjectSource
    {
        return new class(self::SEEN) implements LineProjectSource {
            /** @var array{find: int} */
            public array $calls = ['find' => 0];

            public function __construct(private readonly string $seen)
            {
            }

            public function find(string $projectId): ?LineProject
            {
                ++$this->calls['find'];

                return $projectId === $this->seen ? new LineProject($projectId, 'DUD', null, false, '/projects/'.$projectId) : null;
            }

            public function ofClient(string $clientId): array
            {
                return [];
            }
        };
    }

    /**
     * The time logged on every project: 90 minutes, and how often it was read.
     *
     * @return LineProjectTime&object{calls: int}
     */
    private function time(): LineProjectTime
    {
        return new class implements LineProjectTime {
            public int $calls = 0;

            public function billableMinutes(string $projectId): int
            {
                ++$this->calls;

                return 90;
            }
        };
    }
}
