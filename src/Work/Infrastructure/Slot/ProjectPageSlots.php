<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Slot;

use Foresi\Ui\Slot\SlotRegistry;
use Foresi\Work\Application\ProjectSummary;
use Foresi\Work\Contract\ProjectClients;
use Foresi\Work\Contract\ProjectSlots;
use Foresi\Work\Infrastructure\Controller\ProjectController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What other modules add to a project's page through ProjectSlots: the
 * sections that follow List and Board as tabs, the one asked for by ?tab=, the
 * facts and the actions of the header, and the cards of its settings. The
 * deal's page shows its sections the same way. The header's first fact is the
 * project's client, where the address book is installed (ProjectClients).
 */
final readonly class ProjectPageSlots
{
    /** A section's key: lowercase letters, digits and dashes. */
    private const string KEY = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';
    /** The client line of the header, a template of Work's Contacts integration. */
    private const string CLIENT_FACT = '@Work/projects/_client_fact.html.twig';
    /** The id of the client combobox of a project's form (projects/_client_field.html.twig). */
    private const string CLIENT_FIELD = 'client';

    public function __construct(private SlotRegistry $slots, private UrlGeneratorInterface $urls, private ?ProjectClients $clients = null)
    {
    }

    /**
     * @return array{sectionTabs: list<array{label: string, href: string, active: bool}>, section: array{key: string, label: string, href: ?string, template: ?string, data: array<string, mixed>}|null, clientFact: ?string, headerFacts: list<array{template: string, data: array<string, mixed>}>, headerActions: list<array{template: string, data: array<string, mixed>}>, slotContext: array<string, mixed>}
     */
    public function of(ProjectSummary $project, string $tab = ''): array
    {
        $tab = 1 === preg_match(self::KEY, $tab) ? $tab : '';
        // A dialog of a section comes back to that section.
        $context = $this->context($project, $this->urls->generate(ProjectController::PROJECT_ROUTE, ['id' => $project->id, 'tab' => '' === $tab ? null : $tab]));
        $sections = $this->sections($context);
        $section = array_values(array_filter($sections, static fn (array $section): bool => $section['key'] === $tab))[0] ?? null;

        return [
            'sectionTabs' => array_map(fn (array $item): array => [
                'label' => $item['label'],
                'href' => $this->urls->generate(ProjectController::PROJECT_ROUTE, ['id' => $project->id, 'tab' => $item['key']]),
                'active' => $item['key'] === ($section['key'] ?? null),
            ], $sections),
            'section' => $section,
            'clientFact' => $this->clients instanceof ProjectClients && null !== $project->clientId ? self::CLIENT_FACT : null,
            'headerFacts' => $this->templated(ProjectSlots::HEADER_FACTS, $context),
            'headerActions' => $this->templated(ProjectSlots::HEADER_ACTIONS, $context),
            'slotContext' => $context,
        ];
    }

    /**
     * The cards other modules add to the project's settings page.
     *
     * @return list<array{template: string, data: array<string, mixed>}>
     */
    public function settings(ProjectSummary $project, string $return): array
    {
        return $this->templated(ProjectSlots::SETTINGS, $this->context($project, $return));
    }

    /**
     * What other modules add to the form of a new project, with the id of its client field.
     *
     * @return list<array{template: string, data: array<string, mixed>}>
     */
    public function newForm(): array
    {
        return $this->templated(ProjectSlots::NEW_FORM, ['clientField' => $this->clients instanceof ProjectClients ? self::CLIENT_FIELD : null]);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(ProjectSummary $project, string $return): array
    {
        return [
            'projectId' => $project->id,
            'clientId' => $project->clientId,
            'return' => $return,
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
    private function templated(string $slot, array $context): array
    {
        $items = [];
        foreach ($this->slots->items($slot, $context) as $item) {
            if (null !== $item->template) {
                $items[] = ['template' => $item->template, 'data' => $item->data + $context];
            }
        }

        return $items;
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
