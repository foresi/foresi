<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

/**
 * Shared Tailwind class maps of the component vocabulary. Looking a key up
 * doubles as validation: an unknown key throws at mount time.
 */
final class Style
{
    public const string FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green';

    private const string BUTTON_BASE = 'inline-flex items-center justify-center gap-[7px] rounded-control border font-medium whitespace-nowrap cursor-pointer no-underline aria-disabled:opacity-50 disabled:opacity-50 disabled:cursor-not-allowed';

    private const array BUTTON_VARIANTS = [
        'primary' => 'bg-green border-green text-white hover:bg-green-hover',
        'secondary' => 'bg-white border-line-strong text-ink hover:bg-head',
        'ghost' => 'bg-transparent border-transparent text-text hover:bg-head',
        'danger' => 'bg-white border-line-strong text-badge-red-text hover:bg-badge-red',
    ];

    private const array BUTTON_SIZES = ['md' => 'h-control', 'sm' => 'h-7 text-[13px]'];

    private const array BUTTON_PADDING = ['md' => 'px-3', 'sm' => 'px-2.5'];

    private const array ICON_BUTTON_WIDTH = ['md' => 'w-control', 'sm' => 'w-7'];

    private const array TONES = [
        'gray' => 'bg-badge-gray text-badge-gray-text',
        'green' => 'bg-badge-green text-badge-green-text',
        'amber' => 'bg-badge-amber text-badge-amber-text',
        'red' => 'bg-badge-red text-badge-red-text',
    ];

    public static function button(string $variant, string $size, bool $iconOnly = false): string
    {
        return implode(' ', [
            self::BUTTON_BASE,
            self::lookup(self::BUTTON_VARIANTS, $variant, 'variant'),
            self::lookup(self::BUTTON_SIZES, $size, 'size'),
            $iconOnly ? self::ICON_BUTTON_WIDTH[$size] : self::BUTTON_PADDING[$size],
            self::FOCUS,
        ]);
    }

    public static function tone(string $tone): string
    {
        return self::lookup(self::TONES, $tone, 'tone');
    }

    /**
     * @param array<string, string> $map
     */
    private static function lookup(array $map, string $key, string $what): string
    {
        return $map[$key] ?? throw new \InvalidArgumentException(sprintf('Unknown %s "%s", expected one of: %s.', $what, $key, implode(', ', array_keys($map))));
    }
}
