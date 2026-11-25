<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Contract;

use Foresi\Shared\Infrastructure\Operation\OperationDefinition;
use Foresi\Shared\Infrastructure\Operation\OperationField;
use Foresi\Tests\Shared\Contract\Legacy\LegacyThingHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The names the contract had before module API 1.0 keep working until 2.0
 * (ADR 0044): each is the class of its new name and reports a deprecation the
 * first time it is loaded, which is why every test runs in a process of its own.
 */
#[RunTestsInSeparateProcesses]
final class DeprecatedAliasesTest extends TestCase
{
    public const array MOVED = [
        \Foresi\Shared\Infrastructure\Operation\OperationHandler::class => \Foresi\Shared\Contract\Operation\OperationHandler::class,
        'Foresi\\Shared\\Infrastructure\\Operation\\OperationOutput' => \Foresi\Shared\Contract\Operation\OperationOutput::class,
        'Foresi\\Shared\\Application\\Api\\Operation' => \Foresi\Shared\Contract\Operation\Operation::class,
        'Foresi\\Shared\\Application\\Api\\Field' => \Foresi\Shared\Contract\Operation\Field::class,
        'Foresi\\Shared\\Application\\Api\\Omitted' => \Foresi\Shared\Contract\Operation\Omitted::class,
        \Foresi\Shared\Infrastructure\Mcp\ReadTool::class => \Foresi\Shared\Contract\Mcp\ReadTool::class,
        \Foresi\Shared\Infrastructure\Mcp\WriteTool::class => \Foresi\Shared\Contract\Mcp\WriteTool::class,
        'Foresi\\Shared\\Infrastructure\\Mcp\\ToolOutput' => \Foresi\Shared\Contract\Mcp\ToolOutput::class,
        'Foresi\\Shared\\Infrastructure\\Mcp\\ToolRefusal' => \Foresi\Shared\Contract\Mcp\ToolRefusal::class,
        \Foresi\Shared\Infrastructure\Mcp\StaffOnlyTool::class => \Foresi\Shared\Contract\Mcp\StaffOnlyTool::class,
        \Foresi\Shared\Application\Refused::class => \Foresi\Shared\Contract\Refused::class,
        \Foresi\Shared\Application\RefusalKind::class => \Foresi\Shared\Contract\RefusalKind::class,
        \Foresi\Shared\Application\Event\DomainEventListener::class => \Foresi\Shared\Contract\Event\DomainEventListener::class,
        'Foresi\\Shared\\Application\\Event\\RecordedEvent' => \Foresi\Shared\Contract\Event\RecordedEvent::class,
        \Foresi\Shared\Application\Event\EventRecorder::class => \Foresi\Shared\Contract\Event\EventRecorder::class,
        'Foresi\\Shared\\Domain\\Event\\DomainEvent' => \Foresi\Shared\Contract\Event\DomainEvent::class,
        \Foresi\Shared\Domain\Event\DomainEventPayload::class => \Foresi\Shared\Contract\Event\DomainEventPayload::class,
        'Foresi\\Shared\\Domain\\Event\\EventSubject' => \Foresi\Shared\Contract\Event\EventSubject::class,
        \Foresi\Instance\Application\Schedule\ScheduledJob::class => \Foresi\Instance\Contract\ScheduledJob::class,
    ];

    /**
     * @param class-string $new
     */
    #[DataProvider('moved')]
    #[IgnoreDeprecations]
    public function testTheOldNameIsTheNewClassAndReportsADeprecation(string $old, string $new): void
    {
        $this->expectUserDeprecationMessage(\sprintf('Since %s 0.11: The "%s" %s is deprecated, use "%s" instead.', str_starts_with($old, 'Foresi\Instance\\') ? 'foresi/instance' : 'foresi/shared', $old, $this->kind($new), $new));

        if (!class_exists($old) && !interface_exists($old)) {
            self::fail(\sprintf('%s is not loaded.', $old));
        }

        self::assertSame($new, new \ReflectionClass($old)->getName());
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function moved(): iterable
    {
        foreach (self::MOVED as $old => $new) {
            yield $old => [$old, $new];
        }
    }

    #[IgnoreDeprecations]
    public function testAnOperationWrittenAgainstTheOldNamesIsStillRead(): void
    {
        $definition = OperationDefinition::of(new LegacyThingHandler());

        self::assertSame('legacy_things.change', $definition->operation->name);
        self::assertSame(['thing_id', 'note'], array_map(static fn (OperationField $field): string => $field->wire, $definition->fields));
        self::assertFalse($definition->fields[1]->required);
        self::assertTrue($definition->fields[1]->nullable);
        self::assertSame('string', $definition->fields[1]->type);
        self::assertSame(10, $definition->fields[1]->describe?->maxLength);
    }

    /**
     * @param class-string $class
     */
    private function kind(string $class): string
    {
        return match (true) {
            enum_exists($class) => 'enum',
            interface_exists($class) => 'interface',
            default => 'class',
        };
    }
}
