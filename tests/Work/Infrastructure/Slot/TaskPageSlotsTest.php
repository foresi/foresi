<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Slot;

use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Ui\Slot\SlotRegistry;
use Foresi\Work\Contract\TaskSlots;
use Foresi\Work\Infrastructure\Slot\TaskPageSlots;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What other modules add under a task's header: nothing without a provider,
 * the facts with a template by weight, each with the task's context.
 */
final class TaskPageSlotsTest extends TestCase
{
    private const string TASK = '01937f4e-0000-7000-8000-000000000002';
    private const string PROJECT = '01937f4e-0000-7000-8000-000000000001';

    public function testWithoutAProviderTheTaskHasNoFacts(): void
    {
        self::assertSame([], $this->slots([])->headerFacts(self::TASK, self::PROJECT));
    }

    public function testTheFactsComeInWeightOrderWithTheTasksContext(): void
    {
        $provider = new readonly class implements SlotProvider {
            public function items(string $slot, array $context): iterable
            {
                return TaskSlots::HEADER_FACTS === $slot ? [
                    new SlotItem(template: '@Test/later.html.twig', data: ['n' => 2], weight: 20),
                    new SlotItem(template: '@Test/meeting.html.twig', data: ['n' => 1], weight: 10),
                    new SlotItem(data: ['no' => 'template']),
                ] : [];
            }
        };

        $facts = $this->slots([$provider])->headerFacts(self::TASK, self::PROJECT);

        $context = ['taskId' => self::TASK, 'projectId' => self::PROJECT, 'return' => '/tasks/'.self::TASK];
        self::assertSame([
            ['template' => '@Test/meeting.html.twig', 'data' => ['n' => 1] + $context],
            ['template' => '@Test/later.html.twig', 'data' => ['n' => 2] + $context],
        ], $facts);
    }

    /**
     * @param list<SlotProvider> $providers
     */
    private function slots(array $providers): TaskPageSlots
    {
        $urls = self::createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => '/tasks/'.(\is_string($parameters['id'] ?? null) ? $parameters['id'] : ''));

        return new TaskPageSlots(new SlotRegistry($providers), $urls);
    }
}
