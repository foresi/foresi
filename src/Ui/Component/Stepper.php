<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The steps of a flow that runs over several screens, with the current one
 * marked, so the user always knows where they are. Steps before the current
 * one are done. On a narrow screen only the label of the current step shows.
 */
#[AsTwigComponent]
final class Stepper
{
    private const int MIN_STEPS = 2;
    private const int MAX_STEPS = 7;

    /** @var list<string> already translated step labels, in order */
    public array $steps = [];

    /** 0-based index of the current step. */
    public int $current = 0;

    /** Accessible name of the whole indicator. */
    public string $label = '';

    /** @var list<array{number: int, label: string, state: string}> */
    private array $views = [];

    #[PostMount]
    public function validate(): void
    {
        $count = \count($this->steps);
        if ($count < self::MIN_STEPS || $count > self::MAX_STEPS) {
            throw new \InvalidArgumentException(\sprintf('A stepper shows %d to %d steps.', self::MIN_STEPS, self::MAX_STEPS));
        }
        if ($this->current < 0 || $this->current >= $count) {
            throw new \InvalidArgumentException('The current step must be one of the steps.');
        }
        if ('' === $this->label) {
            throw new \InvalidArgumentException('A stepper needs a label.');
        }
        foreach ($this->steps as $index => $step) {
            if ('' === $step) {
                throw new \InvalidArgumentException('Every step needs a non-empty label.');
            }
            $this->views[] = [
                'number' => $index + 1,
                'label' => $step,
                'state' => match (true) {
                    $index < $this->current => 'done',
                    $index === $this->current => 'current',
                    default => 'upcoming',
                },
            ];
        }
    }

    /**
     * @return list<array{number: int, label: string, state: string}>
     */
    public function views(): array
    {
        return $this->views;
    }
}
