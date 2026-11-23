<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract\Navigation;

use Foresi\Ui\Contract\Guard;

/**
 * A business area of the navigation: one rail entry with its own sidebar of
 * sections. Areas are UI metadata only. The label and the caption (the process
 * the area covers, in plain words) are translation keys, the icon is in the
 * locked icon set, and areas are shown by weight, then by registration order.
 *
 * "settings" is the one area that is not a business area: it sits apart at the
 * bottom of the rail, whatever its weight.
 *
 * An area defines its sections (NavigationSection), in order. An area that
 * defines none has one section, which is never given a heading. The sidebar
 * shows the section headings only when the area has at least
 * SECTION_HEADINGS_FROM items for the person; below that it is one flat list
 * in the same order.
 */
final readonly class NavigationArea
{
    public const string TAG = 'app.navigation_area';

    public const string SETTINGS = 'settings';

    /** The number of items from which an area shows its section headings. */
    public const int SECTION_HEADINGS_FROM = 7;

    /** @var non-empty-list<NavigationSection> by weight, then by the order given */
    public array $sections;

    /**
     * @param list<NavigationSection> $sections
     */
    public function __construct(
        public string $id,
        public string $labelKey,
        public string $captionKey,
        public string $icon,
        public int $weight = 100,
        array $sections = [],
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $id)) {
            throw new \InvalidArgumentException(sprintf('Navigation area id "%s" must be lowercase letters, digits and underscores.', $id));
        }
        if ('' === $labelKey || '' === $captionKey) {
            throw new \InvalidArgumentException('A navigation area needs a label key and a caption key.');
        }
        Guard::icon($icon);
        $keys = array_map(static fn (NavigationSection $section): string => $section->key, $sections);
        if (\count($keys) !== \count(array_unique($keys))) {
            throw new \InvalidArgumentException(sprintf('Navigation area "%s" defines a section twice.', $id));
        }
        // Sorting is stable, so sections of equal weight keep the order given.
        usort($sections, static fn (NavigationSection $a, NavigationSection $b): int => $a->weight <=> $b->weight);
        $this->sections = [] === $sections ? [new NavigationSection(NavigationSection::DEFAULT, $labelKey)] : $sections;
    }

    /**
     * The section an item belongs to: the one it names, or the last section
     * when it names none or one this area does not define, so an item can
     * never break the sidebar.
     */
    public function section(?string $key): NavigationSection
    {
        return array_find($this->sections, static fn (NavigationSection $section): bool => $section->key === $key) ?? $this->sections[array_key_last($this->sections)];
    }
}
