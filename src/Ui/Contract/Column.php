<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract;

/**
 * The column of a list that shows one attribute of its data source (spec DS1): the attribute's
 * key, label and defaults, aligned by its type, sortable by its key when the list's query can
 * sort by it, and its cell, the attribute's own or its value formatted by type. The registry of
 * the screens derives it; a module declares attributes, never columns.
 */
final readonly class Column
{
    public const array ALIGNS = ['left', 'right', 'center'];

    public string $key;
    public string $label;
    public bool $defaultVisible;
    public int $defaultPosition;
    public ?int $defaultWidth;
    public int $minWidth;
    public string $align;
    /** The attribute's key when the list's query sorts by it, as ?sort= names it. */
    public ?string $sortKey;
    public bool $locked;
    public bool $phone;
    public bool $wide;
    public bool $truncate;

    /**
     * @param \Closure(mixed, array<string, mixed>): mixed $cell the cell of one row, given the row as the list passes it and the list's context
     */
    public function __construct(public Attribute $attribute, private \Closure $cell)
    {
        $this->key = $attribute->key;
        $this->label = $attribute->label;
        $this->defaultVisible = $attribute->defaultVisible;
        $this->defaultPosition = $attribute->defaultPosition;
        $this->defaultWidth = $attribute->defaultWidth;
        $this->minWidth = $attribute->minWidth;
        $this->align = $attribute->type->numeric() ? 'right' : 'left';
        $this->sortKey = $attribute->sortable() ? $attribute->key : null;
        $this->locked = $attribute->locked;
        $this->phone = $attribute->phone;
        $this->wide = $attribute->wide;
        $this->truncate = $attribute->truncate;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function cell(mixed $row, array $context = []): mixed
    {
        return ($this->cell)($row, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function available(array $context = []): bool
    {
        return $this->attribute->available($context);
    }
}
