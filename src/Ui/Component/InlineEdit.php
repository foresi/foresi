<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * One property of a row or a card changed where it is shown, without opening the record: the
 * value is a button that looks as the value does, and choosing a new one saves it at once
 * (Linear, "Assigning issues": click the avatar of an issue in a list for the assignee menu;
 * GitHub Projects, "About issue fields": click a cell of the table to edit it). The read and the
 * edit view look the same, the edit one only shows a frame on hover (Atlassian, Inline edit).
 *
 * It is a form of its own that posts "name" with the chosen value to "action":
 * - "menu": a Ui:Menu of the options with a check on the chosen one, such as a status or a
 *   priority; each row is a submit button of the form. An option with a tone has its dot.
 * - "person": a compact Ui:PersonPicker of "people", with "noneLabel" for nobody.
 * - "date": the browser's own date picker, opened from the button; choosing a day or clearing
 *   it saves (inline_date_controller.js).
 *
 * Without JavaScript nothing of this can open, so the value is shown as plain text instead and
 * the record's own page stays the place to change it.
 */
#[AsTwigComponent]
final class InlineEdit
{
    /** The trigger: the value as it reads, with a frame on hover and while its picker is open. */
    public const string TRIGGER = 'inline-flex min-h-control-sm max-w-full min-w-0 cursor-pointer items-center gap-1.5 rounded-option border border-transparent bg-transparent px-1.5 text-left text-text transition-colors hover:border-control-line hover:bg-white aria-expanded:border-green aria-expanded:bg-white '.Style::FOCUS;

    private const array KINDS = ['menu', 'person', 'date'];

    private const array DOTS = ['gray' => 'bg-badge-gray-text', 'amber' => 'bg-badge-amber-text', 'green' => 'bg-badge-green-text', 'red' => 'bg-badge-red-text'];

    public string $id = '';
    public string $kind = 'menu';
    /** Where the form posts. */
    public string $action = '';
    /** The form field the chosen value is posted as. */
    public string $name = '';
    /** The name of the property, such as "Status": said before the value to a screen reader. */
    public string $label = '';
    /** The chosen value; empty for none. */
    public string $value = '';
    /** The CSRF token the form sends as "_token". */
    public ?string $token = null;
    /** @var array<mixed> hidden fields, such as where to come back to */
    public array $fields = [];
    /** @var array<mixed> list of ["value" => string, "label" => string, "tone" => ?string] for a menu */
    public array $options = [];
    /** @var array<mixed> list of ["value" => id, "name" => full name, "seed" => colour seed] for a person */
    public array $people = [];
    /** The row of nobody of a person picker. */
    public ?string $noneLabel = null;
    /** What the value reads as, such as a formatted date; the label of the chosen option when not given. */
    public ?string $text = null;
    /** The tone of a badge the value is shown in, such as a status or a date past due. */
    public ?string $tone = null;
    /** What a screen reader hears for no value, such as "No date"; a dash is shown. */
    public string $emptyLabel = '';
    /** An icon shown for no value instead of the dash, on a card. */
    public ?string $emptyIcon = null;

    /** @var list<array{value: string, label: string, dot: ?string}> */
    private array $checked = [];

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->id || '' === $this->name || '' === $this->action) {
            throw new \InvalidArgumentException('An inline edit needs an id, a name and an action.');
        }
        if ('' === trim($this->label)) {
            throw new \InvalidArgumentException('An inline edit needs a label.');
        }
        if (!\in_array($this->kind, self::KINDS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown inline edit kind "%s", expected one of: %s.', $this->kind, implode(', ', self::KINDS)));
        }
        Style::href($this->action);
        Style::icon($this->emptyIcon);
        if (null !== $this->tone) {
            Style::tone($this->tone);
        }
        foreach ($this->fields as $field => $fieldValue) {
            if (!\is_string($field) || !\is_string($fieldValue)) {
                throw new \InvalidArgumentException('The hidden fields of an inline edit are names and texts.');
            }
        }
        $this->checked = [];
        foreach ($this->options as $option) {
            $value = \is_array($option) ? $option['value'] ?? null : null;
            $label = \is_array($option) ? $option['label'] ?? null : null;
            $tone = \is_array($option) ? $option['tone'] ?? null : null;
            if (!\is_string($value) || !\is_string($label) || '' === $label || (null !== $tone && (!\is_string($tone) || !isset(self::DOTS[$tone])))) {
                throw new \InvalidArgumentException('Each option of an inline edit needs a value, a label and a known tone or none.');
            }
            $this->checked[] = ['value' => $value, 'label' => $label, 'dot' => null === $tone ? null : self::DOTS[$tone]];
        }
        if ('menu' === $this->kind && [] === $this->checked) {
            throw new \InvalidArgumentException('An inline edit menu needs options.');
        }
    }

    /**
     * @return list<array{value: string, label: string, dot: ?string}>
     */
    public function rows(): array
    {
        return $this->checked;
    }

    /**
     * What the trigger shows: the text given, else the label of the chosen option; empty for none.
     */
    public function shown(): string
    {
        if (null !== $this->text) {
            return $this->text;
        }
        foreach ($this->checked as $option) {
            if ($option['value'] === $this->value) {
                return $option['label'];
            }
        }

        return '';
    }

    /**
     * @return array<string, string> the hidden fields, the token first
     */
    public function hidden(): array
    {
        $fields = null === $this->token ? [] : ['_token' => $this->token];
        foreach ($this->fields as $field => $value) {
            if (\is_string($field) && \is_string($value)) {
                $fields[$field] = $value;
            }
        }

        return $fields;
    }
}
