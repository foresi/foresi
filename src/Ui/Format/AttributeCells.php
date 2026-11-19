<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Format;

use Foresi\Shared\Domain\Countries;
use Foresi\Shared\Domain\Money;
use Foresi\Shared\Domain\View\AttributeType;
use Foresi\Ui\Component\DataTable;
use Foresi\Ui\Component\Style;
use Foresi\Ui\Contract\Attribute;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The cell of an attribute in a Ui:DataTable, one formatter per type, shared by every list
 * (spec DS4): money with its currency, dates in the format of the language, an enum by the label
 * of its value, a boolean as Yes or No, an e-mail, phone or web address as a link chip, a
 * country by its name. An attribute with a cell of its own renders that instead.
 *
 * A datetime is shown as its value reads; the module gives it in the instance's zone.
 */
final readonly class AttributeCells
{
    public function __construct(private TranslatorInterface $translator, private MoneyText $money)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function cell(Attribute $attribute, mixed $row, array $context = []): mixed
    {
        return $attribute->hasCell() ? $attribute->cell($row, $context) : $this->format($attribute, $attribute->value($row, $context));
    }

    /**
     * The value as the table shows it: a text, a link chip, or null for an empty cell.
     *
     * @return string|array{links: list<array{text: string, href: string, opens: string}>, plain: true}|null
     */
    private function format(Attribute $attribute, mixed $value): string|array|null
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return match ($attribute->type) {
            AttributeType::Money => $value instanceof Money ? $this->money->shown($value) : $this->text($value),
            AttributeType::Number => $this->number($value),
            AttributeType::Date => $value instanceof \DateTimeInterface ? $value->format($this->translator->trans('format.date')) : $this->text($value),
            AttributeType::DateTime => $value instanceof \DateTimeInterface ? $value->format($this->translator->trans('format.datetime')) : $this->text($value),
            AttributeType::Enum => $this->option($attribute, $value),
            AttributeType::Boolean => $this->translator->trans(true === $value ? 'ui.attribute.yes' : 'ui.attribute.no'),
            AttributeType::Email => $this->link($this->text($value), 'mailto:'),
            AttributeType::Phone => $this->phone($this->text($value)),
            AttributeType::Url => $this->link($this->text($value), 1 === preg_match('#^https?://#i', $this->text($value)) ? '' : 'https://'),
            AttributeType::Country => \is_string($value) ? Countries::name($value, $this->translator->getLocale()) : $this->text($value),
            AttributeType::Text, AttributeType::Reference => $this->text($value),
        };
    }

    private function text(mixed $value): string
    {
        return match (true) {
            $value instanceof \BackedEnum => $this->text($value->value),
            \is_scalar($value), $value instanceof \Stringable => (string) $value,
            default => throw new \InvalidArgumentException(\sprintf('A value of type %s cannot be shown as text.', get_debug_type($value))),
        };
    }

    private function number(mixed $value): string
    {
        $formatter = new \NumberFormatter($this->translator->getLocale(), \NumberFormatter::DECIMAL);
        if (\is_int($value)) {
            return (string) $formatter->format($value, \NumberFormatter::TYPE_INT64);
        }

        // A decimal string keeps its digits; only its separator follows the language.
        return str_replace('.', $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL), $this->text($value));
    }

    private function option(Attribute $attribute, mixed $value): string
    {
        $value = $this->text($value);
        $label = $attribute->options[$value] ?? null;

        return null === $label ? $value : $this->translator->trans($label);
    }

    /**
     * Digits and a leading plus make the number to dial; a number without any stays text.
     *
     * @return string|array{links: list<array{text: string, href: string, opens: string}>, plain: true}
     */
    private function phone(string $text): string|array
    {
        $dial = preg_replace('/(?!^\+)[^0-9]/', '', trim($text)) ?? '';

        return '' === trim($dial, '+') ? $text : $this->chip($text, 'tel:'.$dial);
    }

    /**
     * @return string|array{links: list<array{text: string, href: string, opens: string}>, plain: true}
     */
    private function link(string $text, string $prefix): string|array
    {
        $href = $prefix.trim($text);
        try {
            Style::href($href);
        } catch (\InvalidArgumentException) {
            return $text;
        }

        return $this->chip($text, $href);
    }

    /**
     * A link chip, as Ui:DataTable shows the records a row refers to; it leaves Turbo.
     *
     * @return array{links: list<array{text: string, href: string, opens: string}>, plain: true}
     */
    private function chip(string $text, string $href): array
    {
        return ['links' => [['text' => $text, 'href' => $href, 'opens' => DataTable::OPENS_PAGE]], 'plain' => true];
    }
}
