<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * A message box: flash messages, form errors and warnings. An error is announced
 * at once (role alert); the other tones wait for a pause (role status).
 */
#[AsTwigComponent]
final class Alert
{
    private const array TONES = [
        'success' => 'green',
        'error' => 'red',
        'warning' => 'amber',
        'info' => 'gray',
    ];

    public string $tone = 'info';
    public ?string $title = null;
    public ?string $icon = null;

    #[PostMount]
    public function validate(): void
    {
        if (!isset(self::TONES[$this->tone])) {
            throw new \InvalidArgumentException(sprintf('Unknown tone "%s", expected one of: %s.', $this->tone, implode(', ', array_keys(self::TONES))));
        }
        Style::icon($this->icon);
    }

    public function role(): string
    {
        return 'error' === $this->tone ? 'alert' : 'status';
    }

    public function classes(): string
    {
        return 'rounded-panel px-4 py-3 text-small '.Style::tone(self::TONES[$this->tone]);
    }
}
