<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Infrastructure\Slot;

use Foresi\Ui\Contract\Slot\SlotItem;
use Foresi\Ui\Contract\Slot\SlotProvider;
use Foresi\Ui\Slot\SlotRegistry;
use Foresi\Work\Application\ProjectSummary;
use Foresi\Work\Contract\ProjectSlots;
use Foresi\Work\Domain\WorkflowTemplate;
use Foresi\Work\Infrastructure\Slot\ProjectPageSlots;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What other modules add to a project's page: sections as tabs by weight, the
 * one ?tab= names, and the header facts, each with the project's context.
 */
final class ProjectPageSlotsTest extends TestCase
{
    private const string PROJECT = '01937f4e-0000-7000-8000-000000000001';

    public function testAProjectSectionSlotRendersInWeightOrder(): void
    {
        $slots = $this->slots([
            new SlotItem(data: ['key' => 'budget', 'label' => 'Budget', 'href' => '/budget'], weight: 30),
            new SlotItem(data: ['key' => 'activities', 'label' => 'Activities', 'href' => '/activities'], weight: 10),
            new SlotItem(template: '@Test/deal.html.twig', data: ['key' => 'deal', 'label' => 'Deal'], weight: 20),
            // Left out: a key that is not one, no label, an address elsewhere and nothing to show.
            new SlotItem(data: ['key' => 'Bad Key', 'label' => 'Bad', 'href' => '/bad']),
            new SlotItem(data: ['key' => 'nolabel', 'href' => '/nolabel']),
            new SlotItem(data: ['key' => 'away', 'label' => 'Away', 'href' => 'https://example.com/away']),
            new SlotItem(data: ['key' => 'empty', 'label' => 'Empty']),
        ], [
            new SlotItem(template: '@Test/budget_fact.html.twig', data: ['hours' => 8], weight: 5),
            new SlotItem(data: ['no' => 'template']),
        ]);

        $page = $slots->of($this->project(), 'budget');

        self::assertSame(['Activities', 'Deal', 'Budget'], array_column($page['sectionTabs'], 'label'));
        self::assertSame([
            '/projects/'.self::PROJECT.'?tab=activities',
            '/projects/'.self::PROJECT.'?tab=deal',
            '/projects/'.self::PROJECT.'?tab=budget',
        ], array_column($page['sectionTabs'], 'href'));
        self::assertSame([false, false, true], array_column($page['sectionTabs'], 'active'));
        self::assertNotNull($page['section']);
        self::assertSame('budget', $page['section']['key']);
        self::assertSame('/budget', $page['section']['href']);
        $context = ['projectId' => self::PROJECT, 'clientId' => 'client-a', 'return' => '/projects/'.self::PROJECT.'?tab=budget'];
        self::assertSame($context, $page['slotContext']);
        self::assertSame([['template' => '@Test/budget_fact.html.twig', 'data' => ['hours' => 8] + $context]], $page['headerFacts']);
    }

    public function testWithoutATabTheTasksStayShown(): void
    {
        $slots = $this->slots([new SlotItem(data: ['key' => 'activities', 'label' => 'Activities', 'href' => '/activities'])], []);

        foreach (['', 'nothing', '../x'] as $tab) {
            $page = $slots->of($this->project(), $tab);
            self::assertNull($page['section'], $tab);
            self::assertSame([false], array_column($page['sectionTabs'], 'active'));
            self::assertSame('/projects/'.self::PROJECT.('nothing' === $tab ? '?tab=nothing' : ''), $page['slotContext']['return']);
        }
    }

    /**
     * @param list<SlotItem> $sections
     * @param list<SlotItem> $facts
     */
    private function slots(array $sections, array $facts): ProjectPageSlots
    {
        $provider = new readonly class($sections, $facts) implements SlotProvider {
            /**
             * @param list<SlotItem> $sections
             * @param list<SlotItem> $facts
             */
            public function __construct(private array $sections, private array $facts)
            {
            }

            public function items(string $slot, array $context): iterable
            {
                return match ($slot) {
                    ProjectSlots::SECTIONS => $this->sections,
                    ProjectSlots::HEADER_FACTS => $this->facts,
                    default => [],
                };
            }
        };
        $urls = self::createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static function (string $route, array $parameters): string {
            $id = $parameters['id'] ?? null;
            $tab = $parameters['tab'] ?? null;
            self::assertIsString($id);

            return '/projects/'.$id.(\is_string($tab) ? '?tab='.$tab : '');
        });

        return new ProjectPageSlots(new SlotRegistry([$provider]), $urls);
    }

    private function project(): ProjectSummary
    {
        return new ProjectSummary(self::PROJECT, 'Roof audit', 'client-a', 'Client A', '', WorkflowTemplate::Simple, null);
    }
}
