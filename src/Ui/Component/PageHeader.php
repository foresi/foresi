<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The header of a page: its breadcrumbs, its title with badges, and its actions.
 *
 * A record whose title is changed where it is read gives "titleEdit": clicking the title, or
 * the pencil button beside it for a keyboard and a screen reader, turns it into a text field
 * that looks as the title does; Enter saves, Escape cancels and leaving it changed saves
 * (Linear, "Creating issues": click directly on the title; Atlassian, Inline edit). It is a
 * form that posts "name" with the CSRF token as "_token" to "action". Without JavaScript the
 * pencil is a link to "href", the page with the form open ("open"), whose Cancel goes back to
 * "cancelHref". A title it cannot have comes back open with its "error" and what was typed
 * ("value"). The title is then not a heading while it is being changed: the field is.
 */
#[AsTwigComponent]
final class PageHeader
{
    public string $title = '';

    /**
     * @var array<string, mixed>|null action, token, name, id, label, href and cancelHref; open, value, error and maxlength optional
     */
    public ?array $titleEdit = null;

    /**
     * @var list<array<string, mixed>> each with a string label and an optional href (the last one is the current page)
     */
    public array $crumbs = [];

    /**
     * @var array<string, mixed>|null label and tone
     */
    public ?array $badge = null;

    /**
     * @var list<array<string, mixed>> more badges after it, each with a label, a tone and an optional icon, such as who sees the page's item
     */
    public array $badges = [];

    /** @var list<array{label: string, href: ?string}> */
    private array $crumbViews = [];

    /** @var array{action: string, token: string, name: string, id: string, label: string, href: string, cancelHref: string, open: bool, value: string, error: ?string, maxlength: ?int}|null */
    private ?array $titleEditView = null;

    /** @var array{label: string, tone: string}|null */
    private ?array $badgeView = null;

    /** @var list<array{label: string, tone: string, icon: ?string}> */
    private array $badgeViews = [];

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
        if (null !== $this->titleEdit) {
            $what = 'The title edit of a page header';
            $href = Style::requireString($this->titleEdit, 'href', $what);
            $cancelHref = Style::requireString($this->titleEdit, 'cancelHref', $what);
            Style::href($href);
            Style::href($cancelHref);
            $value = $this->titleEdit['value'] ?? $this->title;
            $error = $this->titleEdit['error'] ?? null;
            $maxlength = $this->titleEdit['maxlength'] ?? null;
            $this->titleEditView = [
                'action' => Style::requireString($this->titleEdit, 'action', $what),
                'token' => Style::requireString($this->titleEdit, 'token', $what),
                'name' => Style::requireString($this->titleEdit, 'name', $what),
                'id' => Style::requireString($this->titleEdit, 'id', $what),
                'label' => Style::requireString($this->titleEdit, 'label', $what),
                'href' => $href,
                'cancelHref' => $cancelHref,
                'open' => true === ($this->titleEdit['open'] ?? false),
                'value' => is_string($value) ? $value : $this->title,
                'error' => is_string($error) && '' !== $error ? $error : null,
                'maxlength' => is_int($maxlength) ? $maxlength : null,
            ];
        }
        $this->badgeView = null === $this->badge ? null : Style::badge($this->badge, 'The page header');
        foreach ($this->badges as $badge) {
            $icon = isset($badge['icon']) && is_string($badge['icon']) ? $badge['icon'] : null;
            Style::icon($icon);
            $this->badgeViews[] = Style::badge($badge, 'The page header') + ['icon' => $icon];
        }
    }

    /**
     * @return array{action: string, token: string, name: string, id: string, label: string, href: string, cancelHref: string, open: bool, value: string, error: ?string, maxlength: ?int}|null
     */
    public function titleEditView(): ?array
    {
        return $this->titleEditView;
    }

    /**
     * @return list<array{label: string, tone: string, icon: ?string}>
     */
    public function badgeViews(): array
    {
        return $this->badgeViews;
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
