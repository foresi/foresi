<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The Settings button at the end of a list's tabs and its 400 px side sheet, "List settings" (spec
 * D-display-4 and D-display-6; Linear's display options, Notion's view settings, Attio's view
 * settings, Airtable, HubSpot column pickers), one sheet for how the list shows:
 *
 * - its views, each with a handle, a checkbox for shown in the tabs, Move up / Move down and a "⋯"
 *   menu (Rename, Show count in tab, Duplicate, Hide or Delete), "+ New view" under them; All leads;
 * - the quick filters: every filter field of the list in one list, a checked one a chip under the
 *   tabs, in their order, at most four (Polaris IndexFilters pins filters, Notion keeps them with
 *   the view);
 * - the shown columns first, in their order, each with a checkbox; the column that names the row
 *   is locked, always shown and first;
 * - each movable row has a drag handle and Move up / Move down buttons, so every reorder works
 *   with a single pointer and a keyboard too (WCAG 2.2 SC 2.5.7, Atlassian's drag and drop
 *   guidance); each move is said in a live region;
 * - under "Add column", every other attribute of the list with a checkbox; one checked is added
 *   after the shown ones (spec DS5; Attio "Add column", Airtable, Level);
 * - a search over the columns once there are more than SEARCH_FROM;
 * - the row density, Comfortable (56 px) or Compact (44 px);
 * - "Reset to default", back to the view's own display, and one Apply.
 *
 * Apply first saves the views' order and tabs (an owner's, SavedViewController order), then sends
 * the GET form to the list, which re-renders it with pins, cols (the checked columns in order) and density, the working state of WorkingDisplay. Without JavaScript the checkboxes
 * send cols[] in their order, which the list reads the same way.
 */
#[AsTwigComponent]
final class ColumnPicker
{
    public const int SEARCH_FROM = 10;
    private const array DENSITIES = ['comfortable', 'dense'];

    public string $id = '';
    /** The list's URL, the form's action. */
    public string $action = '';
    /** @var array<string, scalar|null> what the list keeps while its columns change (the view, the filters, the sort): empty values are left out */
    public array $keep = [];
    /** @var list<array<string, mixed>> each with key, label, visible and locked, as WorkingState::picker() gives them */
    public array $columns = [];
    /** The row density, or null for a list whose rows have none to choose. */
    public ?string $density = 'comfortable';
    /** @var list<array<string, mixed>> the fields one can pin, each with key, label and pinned, those pinned first in order (FilterSheet's pinnable) */
    public array $quick = [];
    /**
     * The list's views for the sheet's Views section (ViewBar's manageViews), or null for a list
     * without saved views.
     *
     * @var array<string, mixed>|null
     */
    public ?array $manage = null;
    /**
     * The working state a new view is saved from (ViewBar's viewBar).
     *
     * @var array<string, mixed>|null
     */
    public ?array $viewBar = null;
    /** The list as its view shows it, without the working state. */
    public string $resetHref = '';
    /** Whether the list shows other columns or density than its view. */
    public bool $modified = false;

    /** @var list<array{key: string, label: string, visible: bool, locked: bool, id: string}> */
    private array $views = [];

    /** @var list<array{key: string, label: string, pinned: bool, id: string}> */
    private array $quickViews = [];

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->id || '' === $this->action || '' === $this->resetHref) {
            throw new \InvalidArgumentException('A column picker needs an id, the list\'s URL and the URL that resets it.');
        }
        Style::href($this->action);
        Style::href($this->resetHref);
        if (null !== $this->density && !\in_array($this->density, self::DENSITIES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown density "%s", expected one of: %s.', $this->density, implode(', ', self::DENSITIES)));
        }
        foreach ($this->columns as $column) {
            $key = Style::requireString($column, 'key', 'A column of the picker');
            $this->views[] = [
                'key' => $key,
                'label' => Style::requireString($column, 'label', 'A column of the picker'),
                'visible' => true === ($column['visible'] ?? false) || true === ($column['locked'] ?? false),
                'locked' => true === ($column['locked'] ?? false),
                'id' => $this->id.'-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $key),
            ];
        }
        // The locked columns lead, as the registry puts them first.
        usort($this->views, static fn (array $a, array $b): int => $b['locked'] <=> $a['locked']);
        foreach ($this->quick as $item) {
            $key = Style::requireString($item, 'key', 'A quick filter of the sheet');
            $this->quickViews[] = [
                'key' => $key,
                'label' => Style::requireString($item, 'label', 'A quick filter of the sheet'),
                'pinned' => true === ($item['pinned'] ?? false),
                'id' => $this->id.'-pin-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $key),
            ];
        }
    }

    /**
     * Every filter field of the list, the pinned ones first in their order, each with whether it is
     * a chip under the tabs.
     *
     * @return list<array{key: string, label: string, pinned: bool, id: string}>
     */
    public function quickViews(): array
    {
        return $this->quickViews;
    }

    public function maxPins(): int
    {
        return FilterBar::MAX_PINNED;
    }

    /**
     * The columns the list shows, in their order.
     *
     * @return list<array{key: string, label: string, visible: bool, locked: bool, id: string}>
     */
    public function shown(): array
    {
        return array_values(array_filter($this->views, static fn (array $view): bool => $view['visible']));
    }

    /**
     * What can be added: the other columns, in the list's default order.
     *
     * @return list<array{key: string, label: string, visible: bool, locked: bool, id: string}>
     */
    public function addable(): array
    {
        return array_values(array_filter($this->views, static fn (array $view): bool => !$view['visible']));
    }

    public function searchable(): bool
    {
        return \count($this->views) > self::SEARCH_FROM;
    }

    /**
     * @return array<string, string>
     */
    public function hidden(): array
    {
        $hidden = [];
        foreach ($this->keep as $name => $value) {
            if (null !== $value && '' !== $value && !\in_array($name, ['cols', 'density', 'pins', 'page'], true)) {
                $hidden[$name] = \is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }

        return $hidden;
    }
}
