<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Application\Message\ReadImportFile;
use App\Import\Application\Source\SourceSettings;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Domain\Import;
use App\Import\Domain\ImportMode;
use App\Import\Domain\ImportOptions;
use App\Import\Domain\ImportProfile;
use App\Import\Domain\ImportState;
use App\Import\Domain\Mapping;
use App\Import\Domain\ReplaceScope;
use Psr\Clock\ClockInterface;

/**
 * Stores what the user decided on the mapping screen: the field of each column,
 * what source values mean, the options and the profile. A checked import goes
 * back to mapping, so it has to be checked again.
 */
final readonly class ConfigureImport
{
    public function __construct(
        private Imports $imports,
        private ProfileRepository $profiles,
        private TargetRegistry $targets,
        private ImportJobs $jobs,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param int|null    $profileId      an existing profile of the target the import belongs to
     * @param string|null $newProfileName a new profile to create, when no existing one is given
     *
     * @throws ImportRefused
     */
    public function configure(int $importId, Mapping $mapping, ImportOptions $options, ?int $profileId = null, ?string $newProfileName = null): void
    {
        $this->imports->transactional(function () use ($importId, $mapping, $options, $profileId, $newProfileName): void {
            $import = $this->editable($importId);
            $target = $this->targets->get($import->targetKey());

            $fields = array_map(static fn (\App\Import\Domain\FieldDefinition $field): string => $field->key, $target->fields());
            foreach ($mapping->columns as $column => $key) {
                if ($column < 0 || $column >= \count($import->headers()) || null !== $key && !\in_array($key, $fields, true)) {
                    throw new ImportRefused('import.refused.unknown_field');
                }
            }
            $missing = $mapping->missingRequired($target->fields());
            if ([] !== $missing) {
                throw new ImportRefused('import.refused.required_unmapped', ['fields' => implode(', ', $missing)]);
            }
            $matchKey = RowReader::matchKeyOf($target, $mapping, $options->matchKey);
            if (null !== $options->matchKey && null === $matchKey) {
                throw new ImportRefused('import.refused.match_key');
            }

            $profile = null;
            if (null !== $profileId) {
                $profile = $this->profiles->find($profileId);
                if (!$profile instanceof ImportProfile || $profile->targetKey() !== $import->targetKey()) {
                    throw new ImportRefused('import.refused.unknown_profile');
                }
            } elseif (null !== $newProfileName && '' !== trim($newProfileName)) {
                $profile = ImportProfile::named(trim($newProfileName), $import->targetKey(), $import->presetKey(), $options, \DateTimeImmutable::createFromInterface($this->clock->now()));
                $this->profiles->add($profile);
            }
            if (ImportMode::FullReplace === $options->mode && ReplaceScope::Profile === $options->scope && !$profile instanceof ImportProfile) {
                throw new ImportRefused('import.refused.profile_required');
            }

            // The confirmation belongs to one run; it is typed on the check screen.
            $import->useMapping($mapping, new ImportOptions($options->mode, $options->scope, $matchKey, $options->blankCells, $options->dateOrder, $options->decimalSeparator, null), $profile);
        });
    }

    /**
     * Reads the file again with other settings (encoding, delimiter, sheet, header row).
     *
     * @throws ImportRefused
     */
    public function reread(int $importId, SourceSettings $settings): void
    {
        $this->imports->transactional(function () use ($importId, $settings): void {
            $import = $this->editable($importId);
            $import->moveTo(ImportState::Reading);
            $import->useSourceSettings($settings->toArray());
        });
        $this->jobs->dispatch(new ReadImportFile($importId));
    }

    private function editable(int $importId): Import
    {
        $import = $this->imports->findLocked($importId) ?? throw ImportRefused::notFound();
        if (ImportState::Checked === $import->state()) {
            $import->moveTo(ImportState::Mapping);
        }
        if (ImportState::Mapping !== $import->state()) {
            throw new ImportRefused('import.refused.not_editable');
        }

        return $import;
    }
}
