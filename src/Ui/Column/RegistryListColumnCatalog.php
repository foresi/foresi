<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Column;

use Foresi\Shared\Application\View\ListColumnCatalog;
use Foresi\Ui\Contract\Column;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The columns of the registry as the API and MCP describe a list, labelled in
 * English whatever the language of the screens.
 */
final readonly class RegistryListColumnCatalog implements ListColumnCatalog
{
    public function __construct(private ListColumnRegistry $registry, private TranslatorInterface $translator)
    {
    }

    public function columns(string $listKey): array
    {
        return array_map(fn (Column $column): array => [
            'key' => $column->key,
            'label' => $this->translator->trans($column->label, locale: 'en'),
            'sortable' => null !== $column->sortKey,
            'default' => $column->defaultVisible,
            'locked' => $column->locked,
        ], $this->registry->columns($listKey));
    }
}
