<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * A short, fixed set of options of which any number can be chosen, shown as
 * toggle chips. Each chip is a real checkbox, so the form posts a list.
 */
#[AsTwigComponent]
final class ChoiceChips
{
    public string $name = '';
    public string $id = '';
    public string $legend = '';
    public ?string $help = null;
    public ?string $error = null;

    /**
     * @var array<string, string> value => label
     */
    public array $options = [];

    /**
     * @var list<string>
     */
    public array $values = [];

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->name || '' === $this->id || '' === $this->legend) {
            throw new \InvalidArgumentException('Choice chips need a name, an id and a legend.');
        }
        if ([] === $this->options) {
            throw new \InvalidArgumentException('Choice chips need at least one option.');
        }
        foreach ($this->options as $value => $label) {
            if ('' === $label) {
                throw new \InvalidArgumentException(sprintf('Choice chip "%s" needs a non-empty label.', $value));
            }
        }
    }

    public function isChecked(int|string $value): bool
    {
        return \in_array((string) $value, $this->values, true);
    }

    public function describedBy(): ?string
    {
        $ids = array_filter([
            null !== $this->help ? $this->id.'-help' : null,
            null !== $this->error ? $this->id.'-error' : null,
        ]);

        return [] === $ids ? null : implode(' ', $ids);
    }
}
