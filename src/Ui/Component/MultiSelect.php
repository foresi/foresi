<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Several options ticked from a list that filters as you type, in a popup
 * over a button: an editable combobox over a multi-select listbox (WAI-ARIA
 * APG combobox and listbox patterns), in a dialog popup as Primer's
 * SelectPanel is. A tick stays in the popup; closing it posts the form with
 * every ticked value, once, if anything changed. When "createName" is given
 * and no option is named as typed, the last row creates one by that name and
 * posts it in that field. Without JavaScript the button is a link to "href",
 * a page that does the same with checkboxes.
 */
#[AsTwigComponent]
final class MultiSelect
{
    public string $id = '';
    /** The form field each ticked value is posted as, such as "labels[]". */
    public string $name = '';
    /** The name of the list, such as "Labels": the popup and the listbox are named by it. */
    public string $label = '';
    /** The text of the button that opens the popup. */
    public string $trigger = '';
    public ?string $icon = null;
    /** Where the form posts. */
    public string $action = '';
    /** The page that does the same without JavaScript. */
    public string $href = '';
    /** The CSRF token the form sends as "_token". */
    public ?string $token = null;
    /** @var array<mixed> hidden fields, such as where to come back to */
    public array $fields = [];
    /** @var array<mixed> list of ["value" => string, "label" => string, "tone" => ?string] */
    public array $options = [];
    /** @var array<mixed> the ticked values */
    public array $values = [];
    /** The accessible name of the search field, such as "Search labels". */
    public string $searchLabel = '';
    /** Said when nothing matches and nothing can be created. */
    public string $emptyLabel = '';
    /** The field a new option's name is posted as; null when the person may not create one. */
    public ?string $createName = null;
    /** The text of the create row, with "{name}" where the typed name goes. */
    public string $createLabel = '';

    /** @var list<array{value: string, label: string, tone: ?string, checked: bool}> */
    private array $rows = [];

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->id || '' === $this->name || '' === $this->action || '' === $this->href) {
            throw new \InvalidArgumentException('A multi-select needs an id, a name, an action and a page without JavaScript.');
        }
        if ('' === trim($this->label) || '' === trim($this->trigger) || '' === trim($this->searchLabel)) {
            throw new \InvalidArgumentException('A multi-select needs a label, a trigger text and a search label.');
        }
        if (null !== $this->createName && !str_contains($this->createLabel, '{name}')) {
            throw new \InvalidArgumentException('The create row of a multi-select needs a text with "{name}".');
        }
        Style::href($this->action);
        Style::href($this->href);
        Style::icon($this->icon);
        foreach ($this->fields as $field => $fieldValue) {
            if (!\is_string($field) || !\is_string($fieldValue)) {
                throw new \InvalidArgumentException('The hidden fields of a multi-select are names and texts.');
            }
        }
        $this->rows = [];
        foreach ($this->options as $option) {
            $value = \is_array($option) ? $option['value'] ?? null : null;
            $label = \is_array($option) ? $option['label'] ?? null : null;
            $tone = \is_array($option) ? $option['tone'] ?? null : null;
            if (!\is_string($value) || '' === $value || !\is_string($label) || '' === $label || (null !== $tone && !\is_string($tone))) {
                throw new \InvalidArgumentException('Each option of a multi-select needs a value, a label and a tone or none.');
            }
            if (null !== $tone) {
                Style::tone($tone);
            }
            $this->rows[] = ['value' => $value, 'label' => $label, 'tone' => $tone, 'checked' => \in_array($value, $this->values, true)];
        }
    }

    /**
     * @return list<array{value: string, label: string, tone: ?string, checked: bool}>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function searchClasses(): string
    {
        return 'h-control-sm pl-9 text-small '.Style::field(false);
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
