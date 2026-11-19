<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Foresi\Ui\Column\ListColumnRegistry;
use Foresi\Ui\Contract\Opens;
use Foresi\Ui\Format\AttributeCells;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * One value, shown the same way wherever it appears: in a list cell, a description list or a
 * facts grid. One renderer per value type: a text as text, an enum as a Ui:Badge in the tone its
 * attribute gives the value, a multi-enum as one badge per value, a link chip as links, and
 * nothing (null, '' or an empty list) as Ui:EmptyValue. AttributeCells turns an attribute's value
 * into this shape, so the catalogue decides how a value looks, not the page: given a list and
 * one of its attributes, the value is the attribute's raw value and is shown as its column shows it.
 */
#[AsTwigComponent]
final class Value
{
    /**
     * A text (or a number), a badge {label, tone, hint?}, {badges: list of badges},
     * {links: list of {text, href, opens?}}, or null, '' or [] for not set.
     */
    public mixed $value = null;

    /** The list (such as "party.contacts") of $attribute. */
    public ?string $list = null;

    /** The key of the attribute of $list whose raw value $value is. */
    public ?string $attribute = null;

    /** The words for not set where the general "Not set" would mislead, such as "No response". */
    public ?string $empty = null;

    /**
     * @var array{kind: string, text: string, badges: list<array{label: string, tone: string}>, hint: ?string, links: list<array{text: string, href: string, opens: string}>}
     */
    private array $view = ['kind' => 'empty', 'text' => '', 'badges' => [], 'hint' => null, 'links' => []];

    public function __construct(private readonly ListColumnRegistry $columns, private readonly AttributeCells $cells)
    {
    }

    #[PostMount]
    public function validate(): void
    {
        $value = null === $this->attribute
            ? $this->value
            : $this->cells->shown($this->columns->attribute($this->list ?? throw new \InvalidArgumentException('A value of an attribute names its list.'), $this->attribute), $this->value);
        $this->view = self::view($value);
    }

    /**
     * What a value shows as: its kind (empty, text, badge, badges or links) with its parts.
     *
     * @return array{kind: string, text: string, badges: list<array{label: string, tone: string}>, hint: ?string, links: list<array{text: string, href: string, opens: string}>}
     */
    public static function view(mixed $value): array
    {
        $view = ['kind' => 'empty', 'text' => '', 'badges' => [], 'hint' => null, 'links' => []];
        if (null === $value || '' === $value || [] === $value) {
            return $view;
        }
        if (\is_scalar($value) || $value instanceof \Stringable) {
            return ['kind' => 'text', 'text' => (string) $value] + $view;
        }
        if (!\is_array($value)) {
            throw new \InvalidArgumentException('A value is a text, a badge, badges, links or null.');
        }
        if (isset($value['badges'])) {
            if (!\is_array($value['badges'])) {
                throw new \InvalidArgumentException('The badges of a value must be a list.');
            }
            $badges = array_values(array_map(static fn (mixed $badge): array => Style::badge($badge, 'A value'), $value['badges']));

            return [] === $badges ? $view : ['kind' => 'badges', 'text' => implode(', ', array_column($badges, 'label')), 'badges' => $badges] + $view;
        }
        if (isset($value['links'])) {
            if (!\is_array($value['links']) || [] === $value['links']) {
                throw new \InvalidArgumentException('The links of a value must be a non-empty list.');
            }
            $links = [];
            foreach ($value['links'] as $link) {
                if (!\is_array($link)) {
                    throw new \InvalidArgumentException('A link of a value must be an array.');
                }
                $href = Style::requireString($link, 'href', 'A link of a value');
                Style::href($href);
                $opens = $link['opens'] ?? Opens::PAGE;
                if (!\in_array($opens, Opens::ALL, true)) {
                    throw new \InvalidArgumentException(\sprintf('A link of a value opens as one of: %s.', implode(', ', Opens::ALL)));
                }
                $links[] = ['text' => Style::requireString($link, 'text', 'A link of a value'), 'href' => $href, 'opens' => $opens];
            }

            return ['kind' => 'links', 'text' => implode(', ', array_column($links, 'text')), 'links' => $links] + $view;
        }
        $badge = Style::badge($value + ['label' => $value['text'] ?? null], 'A value');
        $hint = $value['hint'] ?? null;
        if (null !== $hint && !\is_string($hint)) {
            throw new \InvalidArgumentException('The hint of a value badge must be a string.');
        }

        return ['kind' => 'badge', 'text' => $badge['label'], 'badges' => [$badge], 'hint' => $hint] + $view;
    }

    /**
     * @return array{kind: string, text: string, badges: list<array{label: string, tone: string}>, hint: ?string, links: list<array{text: string, href: string, opens: string}>}
     */
    public function shown(): array
    {
        return $this->view;
    }
}
