<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Slot;

use Foresi\Ui\Slot\SlotRegistry;
use Foresi\Work\Application\ProjectSummary;
use Foresi\Work\Contract\ProjectSlots;
use Foresi\Work\Infrastructure\Controller\ProjectController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What other modules add to a project's page through ProjectSlots: the
 * sections that follow List and Board as tabs, the one asked for by ?tab=, and
 * the facts under the header. The deal's page shows its sections the same way.
 */
final readonly class ProjectPageSlots
{
    /** A section's key: lowercase letters, digits and dashes. */
    private const string KEY = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    public function __construct(private SlotRegistry $slots, private UrlGeneratorInterface $urls)
    {
    }

    /**
     * @return array{sectionTabs: list<array{label: string, href: string, active: bool}>, section: array{key: string, label: string, href: ?string, template: ?string, data: array<string, mixed>}|null, headerFacts: list<array{template: string, data: array<string, mixed>}>, slotContext: array<string, mixed>}
     */
    public function of(ProjectSummary $project, string $tab = ''): array
    {
        $tab = 1 === preg_match(self::KEY, $tab) ? $tab : '';
        $context = [
            'projectId' => $project->id,
            'clientId' => $project->clientId,
            // A dialog of a section comes back to that section.
            'return' => $this->urls->generate(ProjectController::PROJECT_ROUTE, ['id' => $project->id, 'tab' => '' === $tab ? null : $tab]),
        ];
        $sections = $this->sections($context);
        $section = array_values(array_filter($sections, static fn (array $section): bool => $section['key'] === $tab))[0] ?? null;

        return [
            'sectionTabs' => array_map(fn (array $item): array => [
                'label' => $item['label'],
                'href' => $this->urls->generate(ProjectController::PROJECT_ROUTE, ['id' => $project->id, 'tab' => $item['key']]),
                'active' => $item['key'] === ($section['key'] ?? null),
            ], $sections),
            'section' => $section,
            'headerFacts' => $this->facts($context),
            'slotContext' => $context,
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<array{key: string, label: string, href: ?string, template: ?string, data: array<string, mixed>}>
     */
    private function sections(array $context): array
    {
        $sections = [];
        foreach ($this->slots->items(ProjectSlots::SECTIONS, $context) as $item) {
            $key = $item->data['key'] ?? null;
            $label = $item->data['label'] ?? null;
            $href = $item->data['href'] ?? null;
            if (!\is_string($key) || 1 !== preg_match(self::KEY, $key) || !\is_string($label) || '' === $label) {
                continue;
            }
            $href = \is_string($href) ? $this->localPath($href) : null;
            if (null === $href && null === $item->template) {
                continue;
            }
            $sections[] = ['key' => $key, 'label' => $label, 'href' => $href, 'template' => $item->template, 'data' => $item->data + $context];
        }

        return $sections;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<array{template: string, data: array<string, mixed>}>
     */
    private function facts(array $context): array
    {
        $facts = [];
        foreach ($this->slots->items(ProjectSlots::HEADER_FACTS, $context) as $item) {
            if (null !== $item->template) {
                $facts[] = ['template' => $item->template, 'data' => $item->data + $context];
            }
        }

        return $facts;
    }

    /**
     * A local address to load, or null for one that leads elsewhere.
     */
    private function localPath(string $path): ?string
    {
        if ('' === $path || '/' !== $path[0] || \strlen($path) > 2048 || str_starts_with($path, '//') || str_starts_with($path, '/\\') || 1 === preg_match('/[\x00-\x20\x7f\\\\]/', $path)) {
            return null;
        }

        return $path;
    }
}
