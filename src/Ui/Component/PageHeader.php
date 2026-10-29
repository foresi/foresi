<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent]
final class PageHeader
{
    public string $title = '';

    /**
     * @var list<array<string, mixed>> each with a string label and an optional href (the last one is the current page)
     */
    public array $crumbs = [];

    /**
     * @var array<string, mixed>|null label and tone
     */
    public ?array $badge = null;

    /** @var list<array{label: string, href: ?string}> */
    private array $crumbViews = [];

    /** @var array{label: string, tone: string}|null */
    private ?array $badgeView = null;

    #[PostMount]
    public function validate(): void
    {
        if ('' === trim($this->title)) {
            throw new \InvalidArgumentException('A page header needs a title.');
        }
        foreach ($this->crumbs as $crumb) {
            $href = $crumb['href'] ?? null;
            $href = is_string($href) ? $href : null;
            Style::href($href);
            $this->crumbViews[] = ['label' => Style::requireString($crumb, 'label', 'A breadcrumb'), 'href' => $href];
        }
        $this->badgeView = null === $this->badge ? null : Style::badge($this->badge, 'The page header');
    }

    /**
     * @return list<array{label: string, href: ?string}>
     */
    public function crumbViews(): array
    {
        return $this->crumbViews;
    }

    /**
     * @return array{label: string, tone: string}|null
     */
    public function badgeView(): ?array
    {
        return $this->badgeView;
    }
}
