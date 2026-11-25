<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Application;

use Foresi\Sales\Application\LineProject;
use Foresi\Sales\Application\LineProjects;
use Foresi\Sales\Application\LineProjectSource;
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
        $projects = new LineProjects([$source]);

        foreach ([1, 2, 3] as $ignored) {
            self::assertSame('DUD', $projects->find(self::SEEN)?->name);
            self::assertSame(90, $projects->billableMinutes(self::SEEN));
        }
        self::assertSame(['find' => 1, 'minutes' => 1], $source->calls);

        $projects->reset();
        $projects->billableMinutes(self::SEEN);
        self::assertSame(['find' => 2, 'minutes' => 2], $source->calls, 'a new request reads again');
    }

    public function testAProjectThePersonDoesNotSeeHasNoLoggedTime(): void
    {
        $source = $this->source();
        $projects = new LineProjects([$source]);

        self::assertNull($projects->billableMinutes(self::HIDDEN));
        self::assertSame(0, $source->calls['minutes'], 'its time is not read');
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
     * @return LineProjectSource&object{calls: array{find: int, minutes: int}}
     */
    private function source(): LineProjectSource
    {
        return new class(self::SEEN) implements LineProjectSource {
            /** @var array{find: int, minutes: int} */
            public array $calls = ['find' => 0, 'minutes' => 0];

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

            public function billableMinutes(string $projectId): int
            {
                ++$this->calls['minutes'];

                return 90;
            }
        };
    }
}
