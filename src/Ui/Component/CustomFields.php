<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Component;

use Foresi\Shared\Application\Field\FieldReferenceNames;
use Foresi\Shared\Application\Field\ReferenceNames;
use Foresi\Shared\Application\Field\VisibleFields;
use Foresi\Shared\Contract\Field\FieldDefinition;
use Foresi\Shared\Contract\Field\FieldKind;
use Foresi\Shared\Contract\Field\Fields;
use Foresi\Ui\Field\CustomFieldForm;
use Foresi\Ui\Field\CustomFieldText;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The custom fields of a record (ADR 0045) the person sees, in the card "Additional
 * details": in a form, an input of each field by its kind (a text, a number, a date,
 * a URL, a long text, a select, choice chips, a picker of a contact or a person, a
 * checkbox), named as CustomFieldForm reads them; on a detail, each value as a person
 * reads it, an option by its label and a contact linked to its page. Nothing shows
 * when the record has no field the person sees. A required field is marked only on a
 * new record, the one time it is asked for; a short dialog shows only those
 * (requiredOnly). Without the card, the rows stand bare, as in a sidebar or inside
 * a dialog, under the heading "Additional details" when one is asked for (heading),
 * and a detail's rows stack each label over its value, as a sidebar's details do.
 * A form of one field (only) holds that field alone, as a task's sidebar saves each.
 */
#[AsTwigComponent]
final class CustomFields
{
    public const string FORM = 'form';
    public const string DETAIL = 'detail';

    /** The record kind, such as ContactExtension::RECORD. */
    public string $record = '';

    /** form or detail. */
    public string $mode = self::FORM;

    /**
     * @var array<string, string|list<string>>|null what the inputs show, as CustomFieldForm gives them; null for the record's own values
     */
    public ?array $values = null;

    /** @var array<string, string> the translated message of each field that is wrong, by key */
    public array $errors = [];

    /** The record's id: the one a detail shows, or the one a form edits; null for a new record. */
    public ?string $id = null;

    /** Only the required fields, as a short dialog asks for them. */
    public bool $requiredOnly = false;

    /** Inside the card "Additional details"; without it, the rows stand bare. */
    public bool $card = true;

    /** Without the card, under the heading "Additional details". */
    public bool $heading = false;

    /** The one field to show, by its key, such as a field of a task's sidebar; null for all. */
    public ?string $only = null;

    /** @var array<int, FieldDefinition> in the order the fields are declared */
    private array $definitions = [];

    public function __construct(
        private readonly VisibleFields $visible,
        private readonly Fields $fields,
        private readonly CustomFieldForm $form,
        private readonly ReferenceNames $names,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $urls,
        private readonly CustomFieldText $text,
    ) {
    }

    #[PostMount]
    public function validate(): void
    {
        if ('' === $this->record) {
            throw new \InvalidArgumentException('Custom fields name their record kind.');
        }
        if (!\in_array($this->mode, [self::FORM, self::DETAIL], true)) {
            throw new \InvalidArgumentException(\sprintf('Custom fields show as a form or a detail, not "%s".', $this->mode));
        }
        if (self::DETAIL === $this->mode && null === $this->id) {
            throw new \InvalidArgumentException('The custom fields of a detail name the record.');
        }
        $this->definitions = array_filter(
            $this->visible->definitions($this->record),
            fn (FieldDefinition $definition): bool => (!$this->requiredOnly || $definition->required) && (null === $this->only || $definition->key === $this->only),
        );
    }

    public function empty(): bool
    {
        return [] === $this->definitions;
    }

    /**
     * The inputs of a form, in the order the fields are declared.
     *
     * @return list<array{key: string, kind: string, id: string, name: string, textName: string, label: string, value: string, values: list<string>, text: string, options: array<string, string>, required: bool, error: ?string, wide: bool, url: string, type: string, inputmode: ?string}>
     */
    public function inputs(): array
    {
        $values = $this->values ?? $this->form->values($this->record, $this->id);
        $creating = null === $this->id;
        $inputs = [];
        foreach ($this->definitions as $definition) {
            $key = $definition->key;
            $value = $values[$key] ?? '';
            $options = [];
            foreach ($definition->options as $option => $label) {
                $options[$option] = $this->translator->trans($label);
            }
            $reference = FieldKind::Contact === $definition->kind || FieldKind::User === $definition->kind;
            $inputs[] = [
                'key' => $key,
                'kind' => $definition->kind->value,
                'id' => 'custom-field-'.str_replace('_', '-', $key),
                'name' => CustomFieldForm::NAME.'['.$key.']',
                'textName' => CustomFieldForm::TEXT_NAME.'['.$key.']',
                'label' => $this->translator->trans($definition->label),
                'value' => \is_string($value) ? $value : '',
                'values' => \is_array($value) ? $value : [],
                'text' => \is_string($values[$key.CustomFieldForm::TEXT] ?? null) ? $values[$key.CustomFieldForm::TEXT] : '',
                'options' => $options,
                'required' => $creating && $definition->required,
                'error' => $this->errors[$key] ?? null,
                'wide' => \in_array($definition->kind, [FieldKind::LongText, FieldKind::MultiSelect], true),
                'url' => $reference ? $this->urls->generate(FieldReferenceNames::ROUTE, ['kind' => $definition->kind->value]) : '',
                'type' => match ($definition->kind) {
                    FieldKind::Date => 'date',
                    FieldKind::DateTime => 'datetime-local',
                    FieldKind::Url => 'url',
                    default => 'text',
                },
                'inputmode' => FieldKind::Number === $definition->kind ? 'decimal' : null,
            ];
        }

        return $inputs;
    }

    /**
     * The rows of a detail: each field's label and its value as Ui:Value shows it.
     *
     * @return list<array{label: string, value: mixed}>
     */
    public function items(): array
    {
        $id = $this->id ?? throw new \LogicException('A detail names its record.');
        $items = [];
        foreach ($this->definitions as $definition) {
            $items[] = ['label' => $this->translator->trans($definition->label), 'value' => $this->shown($definition, $this->fields->value($this->record, $id, $definition->key))];
        }

        return $items;
    }

    private function shown(FieldDefinition $definition, mixed $value): mixed
    {
        if (null === $value || '' === $value || [] === $value) {
            return null;
        }

        return match ($definition->kind) {
            FieldKind::Url => \is_string($value) ? ['links' => [['text' => $value, 'href' => $value]]] : null,
            FieldKind::Contact, FieldKind::User => \is_string($value) ? $this->reference($definition, $value) : null,
            default => $this->text->text($definition, $value),
        };
    }

    private function reference(FieldDefinition $definition, string $id): mixed
    {
        $names = $this->names->of($definition->kind);
        $name = $names?->names([$id])[$id] ?? null;
        if (!$names instanceof FieldReferenceNames || null === $name) {
            return $this->translator->trans('ui.custom_fields.unknown');
        }
        $url = $names->url($id);

        return null === $url ? $name : ['links' => [['text' => $name, 'href' => $url]]];
    }
}
