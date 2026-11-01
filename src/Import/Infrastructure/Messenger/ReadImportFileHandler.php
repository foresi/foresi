<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Messenger;

use App\Import\Application\Imports;
use App\Import\Application\Message\ReadImportFile;
use App\Import\Application\PresetRegistry;
use App\Import\Application\ProfileRepository;
use App\Import\Application\RowReader;
use App\Import\Application\Source\SourceReader;
use App\Import\Application\Source\SourceRejected;
use App\Import\Application\Source\SourceSettings;
use App\Import\Application\Target\ImportTarget;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Domain\ColumnMatcher;
use App\Import\Domain\ColumnProposal;
use App\Import\Domain\FieldType;
use App\Import\Domain\Import;
use App\Import\Domain\ImportOptions;
use App\Import\Domain\ImportProfile;
use App\Import\Domain\ImportState;
use App\Import\Domain\Mapping;
use App\Import\Domain\NormalisationOptions;
use App\Import\Domain\Preset\SourcePreset;
use App\Import\Infrastructure\Doctrine\RowStager;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads the uploaded file: detects how it is written, stages every data row in
 * chunks of 500 and proposes the field of each column. Handling it twice reads
 * the file again from scratch.
 */
#[AsMessageHandler]
final readonly class ReadImportFileHandler
{
    private const array LABEL_LOCALES = ['cs', 'en'];

    public function __construct(
        private Imports $imports,
        private SourceReader $reader,
        private RowStager $stager,
        private TargetRegistry $targets,
        private ProfileRepository $profiles,
        private PresetRegistry $presets,
        private ColumnMatcher $matcher,
        private TranslatorInterface $translator,
        private TenantFiles $tenantFiles,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ReadImportFile $message): void
    {
        $import = $this->imports->findFresh($message->importId);
        // Unknown in this tenant (the filter hides other tenants' imports), or read already.
        if (!$import instanceof Import || !\in_array($import->state(), [ImportState::Uploaded, ImportState::Reading], true)) {
            return;
        }
        $this->imports->transactional(function () use ($message): void {
            $import = $this->imports->findLocked($message->importId);
            if ($import instanceof Import && ImportState::Uploaded === $import->state()) {
                $import->moveTo(ImportState::Reading);
            }
        });

        try {
            $path = $this->tenantFiles->path($import);
            $inspection = $this->reader->inspect($path, SourceSettings::fromArray($import->sourceSettings()));
            $settings = $inspection->settings();
            $this->imports->transactional(function () use ($message, $path, $inspection, $settings): void {
                $import = $this->imports->findLocked($message->importId);
                if (!$import instanceof Import || ImportState::Reading !== $import->state()) {
                    return;
                }
                $this->imports->deleteRows($import);
                $total = $this->stager->stageRows($import, $this->reader->rows($path, $settings));
                if (0 === $total) {
                    throw SourceRejected::empty();
                }
                $import->useSourceSettings($settings->toArray());
                $import->readHeaders($inspection->headers);
                $import->countRows($total, intdiv($total + RowStager::CHUNK_SIZE - 1, RowStager::CHUNK_SIZE));
                $this->propose($import, $inspection->headers, $this->samplesByColumn($inspection->samples));
                $import->moveTo(ImportState::Mapping);
            });
        } catch (SourceRejected $rejected) {
            $this->fail($message->importId, $rejected->reason);
        } catch (MissingImportFile) {
            $this->fail($message->importId, 'import.failure.file_missing');
        }
    }

    /**
     * @param list<string>             $headers
     * @param array<int, list<string>> $samples
     */
    private function propose(Import $import, array $headers, array $samples): void
    {
        $target = $this->targets->get($import->targetKey());
        $profile = $import->profile() ?? $this->profiles->findByHeaders($target->key(), Mapping::signature($headers));
        $remembered = $profile instanceof ImportProfile && $profile->isRemembered() ? $profile->mapping() : null;
        $preset = $this->presets->find($profile?->presetKey()) ?? $this->presets->recognise($target->key(), $headers);

        $proposals = $this->matcher->propose($headers, $samples, $target->fields(), $remembered, $preset, $this->labels(...));
        $import->propose($proposals, $preset?->key());

        $columns = [];
        foreach ($proposals as $proposal) {
            $columns[$proposal->column] = $proposal->fieldKey;
        }
        $valueMaps = array_replace($preset instanceof SourcePreset ? $preset->values() : [], $remembered instanceof Mapping ? $remembered->valueMaps : []);
        $mapping = new Mapping($columns, $valueMaps);
        $import->useMapping($mapping, $this->options($target, $mapping, $proposals, $samples, $profile), $profile);
    }

    /**
     * The options of the profile, or the standard ones with the date order and
     * decimal separator that read the samples of the proposed columns.
     *
     * @param list<ColumnProposal>     $proposals
     * @param array<int, list<string>> $samples
     */
    private function options(ImportTarget $target, Mapping $mapping, array $proposals, array $samples, ?ImportProfile $profile): ImportOptions
    {
        $base = $profile instanceof ImportProfile ? $profile->options() : ImportOptions::standard();
        $types = [];
        foreach ($target->fields() as $field) {
            $types[$field->key] = $field->type;
        }
        $values = [];
        foreach ($proposals as $proposal) {
            $type = null === $proposal->fieldKey ? null : ($types[$proposal->fieldKey] ?? null);
            if (FieldType::Date === $type || FieldType::Decimal === $type) {
                array_push($values, ...$samples[$proposal->column] ?? []);
            }
        }
        $detected = NormalisationOptions::detect($values);
        $dateOrder = $profile instanceof ImportProfile ? $base->dateOrder : $detected->dateOrder;
        $decimal = $profile instanceof ImportProfile ? $base->decimalSeparator : $detected->decimalSeparator;

        return new ImportOptions($base->mode, $base->scope, RowReader::matchKeyOf($target, $mapping, $base->matchKey), $base->blankCells, $dateOrder, $decimal, null);
    }

    /**
     * @param array<int, list<string>> $rows sample rows by source row number
     *
     * @return array<int, list<string>> sample cells by column
     */
    private function samplesByColumn(array $rows): array
    {
        $columns = [];
        foreach ($rows as $cells) {
            foreach ($cells as $column => $cell) {
                $columns[$column][] = $cell;
            }
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function labels(string $labelKey): array
    {
        return array_values(array_unique(array_map(fn (string $locale): string => $this->translator->trans($labelKey, [], 'messages', $locale), self::LABEL_LOCALES)));
    }

    private function fail(string $importId, string $reason): void
    {
        $this->imports->transactional(function () use ($importId, $reason): void {
            $import = $this->imports->findLocked($importId);
            if ($import instanceof Import && ImportState::Reading === $import->state()) {
                $import->fail($reason, \DateTimeImmutable::createFromInterface($this->clock->now()));
            }
        });
    }
}
