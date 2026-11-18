<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The field of one person, such as who is responsible for a task: a button with the avatar
 * and the name of the chosen person that opens a Ui:Menu of the people, each with their
 * avatar and a check on the chosen one (Linear, "Assigning issues": the assignee menu).
 * A row is a submit button of the form around it, so choosing a person saves it at once.
 *
 * Without JavaScript the menu cannot open, so it steps aside for a plain select with a
 * Save button, as the other properties of a task have.
 *
 * "people" is a list of ["value" => id, "name" => full name, "seed" => colour seed]; with
 * "noneLabel" a first row chooses nobody (the empty value).
 *
 * "compact" is the picker of a row or a card (Ui:InlineEdit): no label above it, the trigger
 * reads as the avatar and the name do, nobody is the dashed avatar alone. Without JavaScript it
 * shows the person as plain text; the record's own page has the full field.
 */
#[AsTwigComponent]
final class PersonPicker
{
    /** The form field the chosen id is posted as. */
    public string $name = '';
    public string $id = '';
    public string $label = '';
    /** The id of the chosen person; empty for nobody. */
    public string $value = '';
    /** @var array<mixed> list of ["value" => id, "name" => full name, "seed" => colour seed] */
    public array $people = [];
    /** The row of nobody, such as "Nobody"; null when someone must be chosen. */
    public ?string $noneLabel = null;
    /** The label of the Save button without JavaScript. */
    public string $saveLabel = 'Save';
    /** The picker of a row or a card: no label shown, the trigger as the value reads. */
    public bool $compact = false;

    /** @var list<array{value: string, name: string, seed: ?string}> */
    private array $checked = [];

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->id || '' === $this->name) {
            throw new \InvalidArgumentException('A person picker needs an id and a name.');
        }
        if ('' === trim($this->label)) {
            throw new \InvalidArgumentException('A person picker needs a label.');
        }
        $this->checked = [];
        foreach ($this->people as $person) {
            $value = \is_array($person) ? $person['value'] ?? null : null;
            $name = \is_array($person) ? $person['name'] ?? null : null;
            $seed = \is_array($person) ? $person['seed'] ?? null : null;
            if (!\is_string($value) || '' === $value || !\is_string($name) || (null !== $seed && !\is_string($seed))) {
                throw new \InvalidArgumentException('Each person of a picker needs a value and a name.');
            }
            $this->checked[] = ['value' => $value, 'name' => $name, 'seed' => $seed];
        }
    }

    /**
     * The chosen person, or null for nobody or an id that is not among the people.
     *
     * @return array{value: string, name: string, seed: ?string}|null
     */
    public function chosen(): ?array
    {
        foreach ($this->rows() as $row) {
            if ('' !== $row['value'] && $row['value'] === $this->value) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The rows of the menu: nobody first when it can be chosen, then the people.
     *
     * @return list<array{value: string, name: string, seed: ?string}>
     */
    public function rows(): array
    {
        return null === $this->noneLabel ? $this->checked : [['value' => '', 'name' => '', 'seed' => null], ...$this->checked];
    }

    /**
     * The options of the select without JavaScript.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = null === $this->noneLabel ? [] : ['' => $this->noneLabel];
        foreach ($this->checked as $person) {
            $options[$person['value']] = $person['name'];
        }

        return $options;
    }

    public function triggerClasses(): string
    {
        return $this->compact
            ? InlineEdit::TRIGGER
            : 'flex h-control w-full cursor-pointer items-center gap-2 text-left hover:border-green '.Style::field(false);
    }
}
