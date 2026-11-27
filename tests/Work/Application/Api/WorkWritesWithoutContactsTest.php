<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application\Api;

use Foresi\Shared\Contract\Operation\Omitted;
use Foresi\Shared\Contract\RefusalKind;
use Foresi\Work\Application\Api\CreateProjectInput;
use Foresi\Work\Application\Api\UpdateProjectInput;
use Foresi\Work\Application\Api\WorkWrites;
use Foresi\Work\Application\Comments;
use Foresi\Work\Application\ManageComments;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Application\Projects;
use Foresi\Work\Application\WorkQueries;
use Foresi\Work\Application\WorkRefused;
use Foresi\Work\Application\WorkUnit;
use Foresi\Work\Application\WorkVisibility;
use PHPUnit\Framework\TestCase;

/**
 * Without the address book a project has no client (decision C2): a write over the API or
 * MCP that names one is refused as invalid, about its clientId, before anything is read or
 * stored.
 */
final class WorkWritesWithoutContactsTest extends TestCase
{
    public function testANewProjectWithAClientIsRefused(): void
    {
        $refused = $this->refused(fn (WorkWrites $writes) => $writes->createProject(new CreateProjectInput('Website', '01900000-0000-7000-8000-000000000001')));

        self::assertSame('client_unavailable', $refused->reason());
        self::assertSame(RefusalKind::Invalid, $refused->kind());
        self::assertSame('clientId', $refused->field());
        self::assertSame('This Foresi has no address book, so a project has no client; leave client_id out.', $refused->getMessage());
    }

    public function testAChangeThatNamesAClientIsRefused(): void
    {
        $refused = $this->refused(fn (WorkWrites $writes) => $writes->updateProject(new UpdateProjectInput('01900000-0000-7000-8000-000000000002', clientId: '01900000-0000-7000-8000-000000000001')));

        self::assertSame('client_unavailable', $refused->reason());
        self::assertSame('clientId', $refused->field());
    }

    public function testAChangeThatClearsTheClientOrLeavesItOutReadsTheProject(): void
    {
        foreach ([null, new Omitted()] as $clientId) {
            $queries = $this->createMock(WorkQueries::class);
            $queries->expects(self::once())->method('project')->willReturn(null);
            try {
                $this->writes($queries)->updateProject(new UpdateProjectInput('01900000-0000-7000-8000-000000000002', clientId: $clientId));
                self::fail('An unknown project is refused.');
            } catch (WorkRefused $refused) {
                self::assertSame('not_found', $refused->reason());
            }
        }
    }

    /**
     * @param \Closure(WorkWrites): mixed $write
     */
    private function refused(\Closure $write): WorkRefused
    {
        $queries = $this->createMock(WorkQueries::class);
        $queries->expects(self::never())->method('project');
        try {
            $write($this->writes($queries));
        } catch (WorkRefused $refused) {
            return $refused;
        }
        self::fail('The write is refused.');
    }

    /**
     * The writes without Work's Contacts integration; the use cases are never reached.
     */
    private function writes(WorkQueries $queries): WorkWrites
    {
        return new WorkWrites(
            self::unreached(ManageTasks::class),
            self::unreached(ManageProjects::class),
            self::unreached(ManageComments::class),
            $queries,
            self::createStub(Comments::class),
            self::unreached(WorkUnit::class),
            self::createStub(Projects::class),
            self::createStub(WorkVisibility::class),
        );
    }

    /**
     * A use case the refusal comes before, never run.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function unreached(string $class): object
    {
        return new \ReflectionClass($class)->newInstanceWithoutConstructor();
    }
}
