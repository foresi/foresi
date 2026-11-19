<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Foresi\Ui\Contract\Guard;

/**
 * Shared Tailwind class maps of the component vocabulary. Looking a key up
 * doubles as validation: an unknown key throws at mount time.
 */
final class Style
{
    /** The focus ring of a button or link: 2 px green, clear of the edge (level.io's buttons). */
    public const string FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green';

    /** The focus ring of a field or menu row: 2 px green drawn just inside the edge (level.io's inputs). */
    public const string FIELD_FOCUS = 'focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-green';

    /**
     * The look of every field (text, select, search, combobox): 4 px radius, soft edge, 12 px padding,
     * a red edge when invalid and a 150 ms colour change (level.io's contact and sign-up forms).
     */
    public static function field(bool $invalid): string
    {
        return 'w-full rounded-control border bg-white px-3 text-ink placeholder:text-muted transition-colors '.($invalid ? 'border-badge-red-text' : 'border-control-line').' disabled:border-divider disabled:bg-head disabled:text-muted '.self::FIELD_FOCUS;
    }

    private const string BUTTON_BASE = 'inline-flex items-center justify-center gap-1.5 rounded-control border font-semibold whitespace-nowrap cursor-pointer no-underline transition-colors aria-disabled:opacity-50 disabled:opacity-50 disabled:cursor-not-allowed';

    /** Secondary and danger buttons wear the control edge on white, as level.io's light buttons do. */
    private const array BUTTON_VARIANTS = [
        'primary' => 'bg-green border-green text-white hover:bg-green-hover',
        'secondary' => 'bg-white border-control-line text-ink hover:bg-head',
        'ghost' => 'bg-transparent border-transparent text-text hover:bg-head',
        'danger' => 'bg-white border-control-line text-badge-red-text hover:bg-badge-red',
        // A filter chip of Ui:FilterBar: empty is quiet with a dashed edge, set sits on the green tint (Calm UI 1A).
        'chip' => 'bg-white border-dashed border-control-line text-muted hover:border-green hover:text-ink',
        'chip-set' => 'bg-transparent border-transparent text-green hover:bg-white/60',
    ];

    private const array BUTTON_SIZES = ['md' => 'h-control', 'sm' => 'h-control-sm text-small'];

    private const array BUTTON_PADDING = ['md' => 'px-3.5', 'sm' => 'px-2.5'];

    /** Icons in a button: 20 px at the full height (level.io), 16 px in a small button. */
    private const array BUTTON_ICON = ['md' => 'size-5 shrink-0', 'sm' => 'size-4 shrink-0'];

    private const array ICON_BUTTON_WIDTH = ['md' => 'w-control', 'sm' => 'w-control-sm'];

    private const array TONES = [
        'gray' => 'bg-badge-gray text-badge-gray-text',
        'green' => 'bg-badge-green text-badge-green-text',
        'amber' => 'bg-badge-amber text-badge-amber-text',
        'red' => 'bg-badge-red text-badge-red-text',
    ];

    public static function button(string $variant, string $size, bool $iconOnly = false, bool $pressed = false): string
    {
        $variantClasses = self::lookup(self::BUTTON_VARIANTS, $variant, 'variant');

        return implode(' ', [
            self::BUTTON_BASE,
            $pressed ? 'bg-green-tint border-green text-green' : $variantClasses,
            self::lookup(self::BUTTON_SIZES, $size, 'size'),
            $iconOnly ? self::ICON_BUTTON_WIDTH[$size] : self::BUTTON_PADDING[$size],
            self::FOCUS,
        ]);
    }

    public static function buttonIcon(string $size): string
    {
        return self::lookup(self::BUTTON_ICON, $size, 'size');
    }

    public static function icon(?string $name): void
    {
        Guard::icon($name);
    }

    /**
     * Only relative URLs and http, https, mailto and tel are allowed.
     */
    public static function href(?string $href): void
    {
        Guard::href($href);
    }

    public static function tone(string $tone): string
    {
        return self::lookup(self::TONES, $tone, 'tone');
    }

    /**
     * Reads a required non-empty string entry of a component item array.
     *
     * @param array<array-key, mixed> $item
     */
    public static function requireString(array $item, string $key, string $what): string
    {
        $value = $item[$key] ?? null;
        if (!is_string($value) || '' === $value) {
            throw new \InvalidArgumentException(sprintf('%s needs a non-empty string "%s".', $what, $key));
        }

        return $value;
    }

    /**
     * Validates a badge definition with a label and an optional tone.
     *
     * @return array{label: string, tone: string}
     */
    public static function badge(mixed $badge, string $what): array
    {
        if (!is_array($badge)) {
            throw new \InvalidArgumentException(sprintf('%s badge must be an array with a label and a tone.', $what));
        }
        $tone = $badge['tone'] ?? 'gray';
        if (!is_string($tone)) {
            throw new \InvalidArgumentException(sprintf('%s badge tone must be a string.', $what));
        }
        self::tone($tone);

        return ['label' => self::requireString($badge, 'label', $what.' badge'), 'tone' => $tone];
    }

    /**
     * @param array<string, string> $map
     */
    private static function lookup(array $map, string $key, string $what): string
    {
        return $map[$key] ?? throw new \InvalidArgumentException(sprintf('Unknown %s "%s", expected one of: %s.', $what, $key, implode(', ', array_keys($map))));
    }
}
