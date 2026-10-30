<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * @phpstan-type Command array{index: int, label: string, href: string, icon: ?string, shortcut: ?string, keywords: string}
 */
#[AsTwigComponent]
final class CommandPalette
{
    public string $id = 'command-palette';

    /**
     * @var list<array<string, mixed>> each with a string label and href, optional group, icon, shortcut and keywords
     */
    public array $commands = [];

    /** @var list<array{label: ?string, index: int, items: list<Command>}> */
    private array $groups = [];

    #[PostMount]
    public function validate(): void
    {
        $byGroup = [];
        foreach ($this->commands as $index => $command) {
            $href = Style::requireString($command, 'href', 'A command');
            Style::href($href);
            $icon = isset($command['icon']) && is_string($command['icon']) ? $command['icon'] : null;
            Style::icon($icon);
            $group = isset($command['group']) && is_string($command['group']) ? $command['group'] : '';
            $byGroup[$group][] = [
                'index' => $index,
                'label' => Style::requireString($command, 'label', 'A command'),
                'href' => $href,
                'icon' => $icon,
                'shortcut' => isset($command['shortcut']) && is_string($command['shortcut']) ? $command['shortcut'] : null,
                'keywords' => isset($command['keywords']) && is_string($command['keywords']) ? $command['keywords'] : '',
            ];
        }
        $position = 0;
        foreach ($byGroup as $group => $items) {
            $this->groups[] = ['label' => '' === $group ? null : $group, 'index' => $position++, 'items' => $items];
        }
    }

    /**
     * @return list<array{label: ?string, index: int, items: list<Command>}>
     */
    public function groups(): array
    {
        return $this->groups;
    }
}
