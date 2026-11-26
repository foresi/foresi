<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Controller;

use Foresi\Inbox\Application\DeclaredAction;
use Foresi\Inbox\Contract\PreFill;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Infrastructure\Operation\OperationField;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The dialog of an inbox action, built from the fields of its operation, so a
 * module's action has a screen without one of its own: a select for a field with
 * values to pick (its enum or the pre-fill's choices), a date or date and time
 * input by the field's format, a text area for a description, body or note or a
 * long text, a number for an int, a checkbox for a bool. Lists are left to the
 * API. "Mark the item done" comes last, ticked by default.
 *
 * Values are kept by the field's PHP name: a string for the text controls, a bool
 * for a checkbox. What the person sends becomes the operation's fields by wire
 * name, for the same input mapper the API uses.
 */
final readonly class ActionForm
{
    public const string MARK_DONE = 'markDone';
    /** The names of a text that takes lines. */
    private const array LONG_TEXT = ['description', 'body', 'note'];
    /** A text that may be longer than this takes lines too. */
    private const int LONG = 500;
    private const string DATE_TIME = 'Y-m-d\TH:i';

    public function __construct(private TranslatorInterface $translator, private InstanceClock $clock)
    {
    }

    /**
     * The fields the dialog shows, in the input's order: neither the path, nor a list, nor markDone.
     *
     * @return list<OperationField>
     */
    public function shown(DeclaredAction $action): array
    {
        return array_values(array_filter($action->definition->bodyFields(), static fn (OperationField $field): bool => 'array' !== $field->type && self::MARK_DONE !== $field->name));
    }

    /**
     * What the dialog starts with: the input's defaults, then what the pre-fill gives.
     *
     * @return array<string, string|bool>
     */
    public function initial(DeclaredAction $action, PreFill $prefill): array
    {
        $defaults = [];
        foreach (new \ReflectionClass($action->definition->input)->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }
        $values = [];
        foreach ([...$this->shown($action), ...$this->markDone($action)] as $field) {
            $value = \array_key_exists($field->name, $prefill->values) ? $prefill->values[$field->name] : ($defaults[$field->name] ?? null);
            $values[$field->name] = $this->formValue($field, $value);
        }
        if ([] === $this->markDone($action)) {
            $values[self::MARK_DONE] = true;
        }

        return $values;
    }

    /**
     * What the person sent, as the dialog shows it again.
     *
     * @param InputBag<string> $post
     *
     * @return array<string, string|bool>
     */
    public function posted(DeclaredAction $action, InputBag $post): array
    {
        $values = [];
        foreach ($this->shown($action) as $field) {
            $values[$field->name] = 'checkbox' === $this->widget($field) ? $post->has($field->name) : mb_substr($post->getString($field->name), 0, 100_000);
        }
        $values[self::MARK_DONE] = $post->has(self::MARK_DONE);

        return $values;
    }

    /**
     * The fields to send to the operation by wire name, and what is wrong with the values, by field name.
     *
     * @param array<string, string|bool> $values
     *
     * @return array{array<string, mixed>, array<string, string>}
     */
    public function sent(DeclaredAction $action, string $itemId, array $values): array
    {
        $sent = [];
        $errors = [];
        foreach ($action->definition->fields as $field) {
            if ($field->inPath) {
                $sent[$field->wire] = $itemId;
            }
        }
        foreach ($this->shown($action) as $field) {
            $value = $values[$field->name] ?? '';
            $widget = $this->widget($field);
            if ('checkbox' === $widget) {
                $sent[$field->wire] = true === $value;
                continue;
            }
            $text = \is_string($value) ? ('textarea' === $widget ? $value : trim($value)) : '';
            if ('' === trim($text)) {
                if ($field->required) {
                    $errors[$field->name] = $this->translator->trans('inbox.action_dialog.required');
                } elseif ($field->nullable) {
                    $sent[$field->wire] = null;
                } elseif ('string' === $field->type) {
                    $sent[$field->wire] = '';
                }
                continue;
            }
            $time = 'datetime' === $widget ? $this->instant($text) : null;
            if ('bool' === $field->type) {
                $sent[$field->wire] = '1' === $text;
            } elseif ('int' === $field->type) {
                if (1 === preg_match('/^-?\d{1,9}$/', $text)) {
                    $sent[$field->wire] = (int) $text;
                } else {
                    $errors[$field->name] = $this->translator->trans('inbox.action_dialog.whole_number');
                }
            } elseif ('datetime' === $widget) {
                if (null !== $time) {
                    $sent[$field->wire] = $time;
                } else {
                    $errors[$field->name] = $this->translator->trans('inbox.action_dialog.date_time');
                }
            } else {
                $sent[$field->wire] = $text;
            }
        }
        if ([] !== $this->markDone($action)) {
            $sent[OperationField::wireName(self::MARK_DONE)] = true === ($values[self::MARK_DONE] ?? false);
        }

        return [$sent, $errors];
    }

    /**
     * The controls of the dialog, for its template: markDone last.
     *
     * @param array<string, string|bool> $values
     * @param array<string, string>      $errors
     *
     * @return list<array<string, mixed>>
     */
    public function fields(DeclaredAction $action, PreFill $prefill, array $values, array $errors): array
    {
        $fields = [];
        foreach ($this->shown($action) as $field) {
            $widget = $this->widget($field, $prefill);
            $value = $values[$field->name] ?? '';
            $fields[] = [
                'name' => $field->name,
                'id' => 'inbox-action-'.$field->wire,
                'widget' => $widget,
                'label' => $this->label($action, $field),
                'required' => $field->required,
                'value' => \is_string($value) ? $value : '',
                'checked' => true === $value,
                'options' => 'select' === $widget ? $this->options($field, $prefill, \is_string($value) ? $value : '') : [],
                'maxLength' => $field->describe?->maxLength,
                'error' => $errors[$field->name] ?? null,
            ];
        }
        $fields[] = [
            'name' => self::MARK_DONE,
            'id' => 'inbox-action-mark_done',
            'widget' => 'checkbox',
            'label' => $this->translator->trans('inbox.action_dialog.mark_done'),
            'required' => false,
            'value' => '',
            'checked' => true === ($values[self::MARK_DONE] ?? true),
            'options' => [],
            'maxLength' => null,
            'error' => $errors[self::MARK_DONE] ?? null,
        ];

        return $fields;
    }

    /**
     * The control of a field: a select for values to pick, a three-way select for a bool that may be
     * left to its default, by the format a date or a date and time, lines for a long text.
     */
    private function widget(OperationField $field, ?PreFill $prefill = null): string
    {
        return match (true) {
            'bool' === $field->type => $field->nullable ? 'select' : 'checkbox',
            'int' === $field->type => 'number',
            null !== $field->describe?->enum, [] !== ($prefill->choices[$field->name] ?? []) => 'select',
            'date' === $field->describe?->format => 'date',
            'date-time' === $field->describe?->format => 'datetime',
            \in_array($field->name, self::LONG_TEXT, true), null !== $field->describe?->maxLength && $field->describe->maxLength > self::LONG => 'textarea',
            default => 'text',
        };
    }

    /**
     * The field's label: the module's translation under inbox_action.<operation>.<field>, else the
     * description of the field.
     */
    private function label(DeclaredAction $action, OperationField $field): string
    {
        $key = 'inbox_action.'.$action->name().'.'.$field->name;
        $label = $this->translator->trans($key);

        return $label !== $key ? $label : ($field->describe->description ?? $field->name);
    }

    /**
     * @return array<int|string, string> by value; PHP keeps a number as an int key
     */
    private function options(OperationField $field, PreFill $prefill, string $value): array
    {
        if ('bool' === $field->type) {
            return ['' => $this->translator->trans('inbox.action_dialog.default'), '1' => $this->translator->trans('inbox.action_dialog.yes'), '0' => $this->translator->trans('inbox.action_dialog.no')];
        }
        $options = $prefill->choices[$field->name] ?? array_combine($field->describe->enum ?? [], $field->describe->enum ?? []);
        if ($field->nullable) {
            return ['' => $this->translator->trans('inbox.action_dialog.none')] + $options;
        }
        if ('' === $value || !\array_key_exists($value, $options)) {
            return ['' => $this->translator->trans('inbox.action_dialog.choose')] + $options;
        }

        return $options;
    }

    /**
     * @return list<OperationField> markDone, when the input has it
     */
    private function markDone(DeclaredAction $action): array
    {
        return array_values(array_filter($action->definition->bodyFields(), static fn (OperationField $field): bool => self::MARK_DONE === $field->name && 'bool' === $field->type));
    }

    private function formValue(OperationField $field, mixed $value): string|bool
    {
        $widget = $this->widget($field);
        if ('checkbox' === $widget) {
            return true === $value;
        }

        return match (true) {
            null === $value => '',
            \is_bool($value) => $value ? '1' : '0',
            \is_int($value) => (string) $value,
            \is_string($value) && 'datetime' === $widget => $this->local($value),
            \is_string($value) => $value,
            default => '',
        };
    }

    /**
     * A date and time of the instance's zone, from the input, as ISO 8601 with its offset.
     */
    private function instant(string $local): ?string
    {
        $time = \DateTimeImmutable::createFromFormat('!'.self::DATE_TIME, $local, $this->clock->timezone());

        return false === $time || $time->format(self::DATE_TIME) !== $local ? null : $time->format(\DATE_ATOM);
    }

    /**
     * An ISO 8601 time as the date and time input shows it, in the instance's zone.
     */
    private function local(string $iso): string
    {
        try {
            return new \DateTimeImmutable($iso)->setTimezone($this->clock->timezone())->format(self::DATE_TIME);
        } catch (\Exception) {
            return '';
        }
    }
}
