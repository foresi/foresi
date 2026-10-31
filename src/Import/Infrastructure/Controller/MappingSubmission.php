<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Controller;

use App\Import\Domain\BlankCells;
use App\Import\Domain\DateOrder;
use App\Import\Domain\DecimalSeparator;
use App\Import\Domain\Import;
use App\Import\Domain\ImportMode;
use App\Import\Domain\ImportOptions;
use App\Import\Domain\Mapping;
use App\Import\Domain\ReplaceScope;
use App\Shared\Domain\Id;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the mapping screen sent: the field of each column, the meaning of the
 * unknown values, the options and the profile. It only reads the form; the
 * import decides whether it is acceptable.
 */
final readonly class MappingSubmission
{
    private const int MAX_PROFILE_NAME = 255;

    private function __construct(
        public Mapping $mapping,
        public ImportOptions $options,
        public ?string $profileId,
        public ?string $profileName,
    ) {
    }

    public static function read(Request $request, Import $import): self
    {
        $form = $request->request;
        $sent = $form->all('columns');
        $columns = [];
        foreach (array_keys($import->headers()) as $column) {
            $key = $sent[$column] ?? '';
            $columns[$column] = \is_string($key) && '' !== $key ? $key : null;
        }

        // Values the screen does not list (those of a preset, for example) stay as they were.
        $valueMaps = $import->mapping()->valueMaps;
        $sources = $form->all('value_sources');
        foreach ($form->all('values') as $field => $values) {
            if (!\is_array($values)) {
                continue;
            }
            foreach ($values as $index => $value) {
                $sent = $sources[$field] ?? null;
                $source = \is_array($sent) ? ($sent[$index] ?? null) : null;
                if (!\is_string($source) || !\is_string($value)) {
                    continue;
                }
                if ('' === $value) {
                    unset($valueMaps[(string) $field][$source]);
                } else {
                    $valueMaps[(string) $field][$source] = $value;
                }
            }
        }
        $valueMaps = array_filter($valueMaps, static fn (array $map): bool => [] !== $map);

        $current = $import->options();
        $mode = ImportMode::tryFrom($form->getString('mode')) ?? $current->mode;
        $scope = ImportMode::FullReplace === $mode ? (ReplaceScope::tryFrom($form->getString('scope')) ?? ReplaceScope::Profile) : null;
        $matchKey = $form->getString('match_key');
        $options = new ImportOptions(
            $mode,
            $scope,
            '' === $matchKey ? null : $matchKey,
            BlankCells::tryFrom($form->getString('blank_cells')) ?? $current->blankCells,
            DateOrder::tryFrom($form->getString('date_order')) ?? $current->dateOrder,
            DecimalSeparator::tryFrom($form->getString('decimal_separator')) ?? $current->decimalSeparator,
            null,
        );

        $profileId = $form->getString('profile_id');
        $profileName = trim(mb_substr($form->getString('profile_name'), 0, self::MAX_PROFILE_NAME));

        return new self(new Mapping($columns, $valueMaps), $options, Id::isValid($profileId) ? $profileId : null, '' === $profileName ? null : $profileName);
    }
}
