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
 *
 * It may end with one action that answers the message, such as Undo after a
 * delete: a small button that posts to its URL, with the CSRF token it is given,
 * as Gmail and Linear put Undo in the message itself.
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
    public ?string $actionLabel = null;
    /** Where the action posts; it goes with its label. */
    public ?string $actionHref = null;
    /** The CSRF token the action sends as _token. */
    public ?string $actionToken = null;

    #[PostMount]
    public function validate(): void
    {
        if ((null === $this->actionLabel || '' === $this->actionLabel) !== (null === $this->actionHref)) {
            throw new \InvalidArgumentException('An alert action has both a label and a URL, or neither.');
        }
        Style::href($this->actionHref);
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
