<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Controller;

use App\Identity\Application\UserRepository;
use App\Import\Application\ImportRefused;
use App\Import\Application\Imports;
use App\Import\Application\PresetRegistry;
use App\Import\Application\RowReader;
use App\Import\Application\Source\Encoding;
use App\Import\Application\Source\SourceFormat;
use App\Import\Application\Source\SourceSettings;
use App\Import\Application\Target\ImportTarget;
use App\Import\Application\Target\TargetRegistry;
use App\Import\Domain\BlankCells;
use App\Import\Domain\ColumnMatcher;
use App\Import\Domain\ColumnProposal;
use App\Import\Domain\DateOrder;
use App\Import\Domain\DecimalSeparator;
use App\Import\Domain\FieldDefinition;
use App\Import\Domain\FieldType;
use App\Import\Domain\Import;
use App\Import\Domain\ImportMode;
use App\Import\Domain\ImportOptions;
use App\Import\Domain\ImportRow;
use App\Import\Domain\ImportState;
use App\Import\Domain\Mapping;
use App\Import\Domain\MatchReason;
use App\Import\Domain\NormalisationOptions;
use App\Import\Domain\Preset\SourcePreset;
use App\Import\Domain\ReplaceScope;
use App\Import\Domain\RowError;
use App\Import\Domain\RowOutcome;
use App\Import\Domain\ValueNormaliser;
use App\Shared\Domain\Countries;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns an import into what its screens show: plain arrays of translated
 * labels and values for the Ui components. It reads, it never changes an import.
 */
final readonly class ImportScreens
{
    /** Rows read for the sample values of the mapping screen. */
    private const int SAMPLE_ROWS = 20;

    /** Rows of one outcome the check previews. */
    public const int PREVIEW_ROWS = 50;

    /** Mapped columns the check preview shows beside the row number. */
    private const int PREVIEW_COLUMNS = 4;

    /** Unknown values listed per field; a free text column mapped to a choice would list thousands. */
    private const int VALUE_LIMIT = 50;

    /** The outcomes the check previews, in tab order. */
    private const array PREVIEW_OUTCOMES = [RowOutcome::Create, RowOutcome::Update, RowOutcome::Restore, RowOutcome::Skip, RowOutcome::Error];

    /** The states whose screen polls the progress. */
    private const array BUSY = [ImportState::Uploaded, ImportState::Reading, ImportState::Checking, ImportState::Queued, ImportState::Running, ImportState::Undoing];

    /** Parameters of the reasons a file is refused, which the failed import does not keep. */
    private const array FAILURE_PARAMETERS = [
        'import.source.rejected.too_large' => ['limit' => 50],
        'import.source.rejected.too_many_rows' => ['limit' => 200000],
        'import.source.rejected.archive_too_large' => ['limit' => 500],
        'import.source.rejected.suspicious_compression' => ['limit' => 100],
    ];

    private const array DELIMITERS = [';' => 'semicolon', ',' => 'comma', "\t" => 'tab', '|' => 'pipe'];

    /** The languages whose field names a column header is compared with. */
    private const array LABEL_LOCALES = ['cs', 'en'];

    /** The refusals of the mapping that belong to one of its fields. */
    private const array FIELD_REFUSALS = ['import.refused.required_unmapped', 'import.refused.match_key', 'import.refused.profile_required'];

    public function __construct(
        private Imports $imports,
        private TargetRegistry $targets,
        private TargetPagesRegistry $pages,
        private PresetRegistry $presets,
        private UserRepository $users,
        private ValueNormaliser $normaliser,
        private ColumnMatcher $matcher,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private ClockInterface $clock,
    ) {
    }

    public static function isBusy(ImportState $state): bool
    {
        return \in_array($state, self::BUSY, true);
    }

    /**
     * @return array{steps: list<string>, current: int}
     */
    public function stepper(Import $import, bool $editingMapping = false): array
    {
        $current = match ($import->state()) {
            ImportState::Uploaded, ImportState::Reading => 0,
            ImportState::Mapping => 1,
            ImportState::Checking => 2,
            ImportState::Checked => $editingMapping ? 1 : 2,
            ImportState::Queued, ImportState::Running => 3,
            default => 4,
        };

        return ['steps' => $this->steps(), 'current' => $current];
    }

    /**
     * @return list<string>
     */
    public function steps(): array
    {
        return array_map(fn (string $step): string => $this->trans('import.steps.'.$step), ['upload', 'columns', 'check', 'run', 'summary']);
    }

    /**
     * Breadcrumbs: the list of the target's records, the history, the import.
     *
     * @return list<array{label: string, href?: string}>
     */
    public function crumbs(string $targetKey, ?Import $import = null): array
    {
        $pages = $this->pages->find($targetKey);
        $crumbs = [];
        $list = $pages?->listUrl();
        if ($pages instanceof TargetPages && null !== $list) {
            $crumbs[] = ['label' => $this->trans($pages->listLabelKey()), 'href' => $list];
        }
        $crumbs[] = ['label' => $this->trans('import.history.title'), 'href' => $this->urls->generate('app_imports')];
        $crumbs[] = ['label' => $import instanceof Import ? $import->originalName() : $this->trans('import.upload.title')];

        return $crumbs;
    }

    public function targetLabel(string $targetKey): string
    {
        return $this->trans($this->targets->get($targetKey)->labelKey());
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string> the labels of the target's fields, or the key of one it does not know
     */
    public function fieldLabels(string $targetKey, array $keys): array
    {
        $fields = $this->fieldsByKey($this->targets->get($targetKey));

        return array_map(fn (string $key): string => isset($fields[$key]) ? $this->trans($fields[$key]->labelKey) : $key, $keys);
    }

    /**
     * @param list<Import> $imports
     *
     * @return list<array<string, mixed>>
     */
    public function historyRows(array $imports): array
    {
        $names = [];

        return array_map(function (Import $import) use (&$names): array {
            $actor = $import->actorUserId();
            $names[$actor] ??= $this->users->findById($actor)?->displayName() ?? '';

            return [
                'id' => (string) $import->id(),
                'href' => $this->urls->generate('app_import_show', ['id' => $import->id()]),
                'label' => $import->originalName(),
                'cells' => [
                    'file' => $import->originalName(),
                    'when' => $this->dateTime($import->createdAt()),
                    'who' => $names[$actor],
                    'target' => $this->targetLabel($import->targetKey()),
                    'mode' => $this->trans('import.mode.'.$import->options()->mode->value.'.label'),
                    'created' => $this->ran($import) ? $this->number($import->created()) : '',
                    'updated' => $this->ran($import) ? $this->number($import->updated() + $import->restored()) : '',
                    'failed' => $this->ran($import) ? $this->number($import->failed()) : '',
                    'state' => $this->stateBadge($import->state()),
                ],
            ];
        }, $imports);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyColumns(): array
    {
        $columns = [];
        foreach (['file' => 'left', 'when' => 'left', 'who' => 'left', 'target' => 'left', 'mode' => 'left', 'created' => 'right', 'updated' => 'right', 'failed' => 'right', 'state' => 'left'] as $key => $align) {
            $columns[] = ['key' => $key, 'label' => $this->trans('import.history.column.'.$key), 'align' => $align];
        }

        return $columns;
    }

    /**
     * @return array{label: string, tone: string}
     */
    public function stateBadge(ImportState $state): array
    {
        return [
            'label' => $this->trans('import.state.'.$state->value),
            'tone' => match ($state) {
                ImportState::Finished, ImportState::Undone => 'green',
                ImportState::FinishedWithErrors, ImportState::Queued => 'amber',
                ImportState::Stopped, ImportState::Failed => 'red',
                default => 'gray',
            },
        ];
    }

    /**
     * Every problem of a refused mapping at the field it belongs to, so the
     * screen marks all of them at once: a required field no column fills at
     * the select of the column it most likely is (a select may carry several),
     * a match key no column fills at the match key, a full replace without a
     * profile at the profile name. A refusal that belongs to no field, or
     * that the fields do not explain, is the form's own message.
     *
     * @return array{columns: array<int, list<string>>, match_key?: string, profile_name?: string, form?: string}
     */
    public function mappingErrors(Import $import, MappingSubmission $submission, ImportRefused $refused, string $refusalText): array
    {
        $mapping = $submission->mapping;
        $options = $submission->options;
        $target = $this->targets->get($import->targetKey());
        $fields = $this->fieldsByKey($target);
        $errors = ['columns' => []];
        foreach ($mapping->missingRequired($target->fields()) as $key) {
            $column = $this->matcher->likelyColumn($import->headers(), $mapping, $fields[$key], $this->labels(...));
            if (null !== $column) {
                $errors['columns'][$column][] = $this->trans('import.mapping.assign', ['field' => $this->trans($fields[$key]->labelKey)]);
            }
        }
        if (null !== $options->matchKey && null === RowReader::matchKeyOf($target, $mapping, $options->matchKey)) {
            $errors['match_key'] = $this->trans('import.refused.match_key');
        }
        if (ImportMode::FullReplace === $options->mode && ReplaceScope::Profile === $options->scope && null === $submission->profileId && null === $submission->profileName) {
            $errors['profile_name'] = $this->trans('import.refused.profile_required');
        }
        $explained = [] !== $errors['columns'] || \count($errors) > 1;
        if (!\in_array($refused->reason, self::FIELD_REFUSALS, true) || !$explained) {
            $errors['form'] = $refusalText;
        }

        return $errors;
    }

    /**
     * Everything the mapping screen shows. The mapping and options are the
     * import's own, or what the user just sent when it was refused, with the
     * errors of that refusal (see mappingErrors()).
     *
     * @param array{columns?: array<int, list<string>>, match_key?: string, profile_name?: string, form?: string} $errors
     *
     * @return array<string, mixed>
     */
    public function mapping(Import $import, ?Mapping $mapping = null, ?ImportOptions $options = null, ?string $profileName = null, bool $editSource = false, array $errors = []): array
    {
        $target = $this->targets->get($import->targetKey());
        $mapping ??= $import->mapping();
        $options ??= $import->options();
        $fields = $this->fieldsByKey($target);
        $rows = $this->imports->firstRows($import, self::SAMPLE_ROWS);
        $samples = $this->samples($rows);

        $proposals = [];
        foreach ($import->proposals() as $proposal) {
            $proposals[$proposal->column] = $proposal;
        }
        $fieldOptions = $this->fieldOptions($target);
        $columns = [];
        foreach ($import->headers() as $column => $header) {
            $value = $mapping->fieldFor($column);
            $columns[] = [
                'id' => 'column-'.$column,
                'name' => 'columns['.$column.']',
                'label' => $header,
                'samples' => $samples[$column] ?? [],
                'value' => $value ?? '',
                'badge' => $this->reasonBadge($proposals[$column] ?? null, $value, $import->presetKey()),
                'errors' => $errors['columns'][$column] ?? [],
            ];
        }

        $required = [];
        foreach ($target->fields() as $field) {
            if ($field->required) {
                $required[$field->key] = $this->trans($field->labelKey);
            }
        }
        $missing = array_map(fn (string $key): string => $required[$key] ?? $key, $mapping->missingRequired($target->fields()));
        $mapped = array_filter($mapping->columns, static fn (?string $key): bool => null !== $key);
        $ambiguity = $this->ambiguity($mapping, $fields, $rows);
        $columnErrors = $errors['columns'] ?? [];
        unset($errors['columns']);

        return [
            'source' => $this->source($import, $editSource),
            'columns' => $columns,
            'fieldOptions' => $fieldOptions,
            'mappedCount' => \count($mapped),
            'required' => $required,
            'missing' => $missing,
            'values' => $this->valueRows($import, $mapping, $fields),
            'modes' => $this->modes(),
            'options' => $options,
            'matchKeys' => $this->matchKeyOptions($target),
            'blankCells' => $this->enumOptions(BlankCells::cases(), 'import.blank_cells.'),
            'scopes' => $this->enumOptions(ReplaceScope::cases(), 'import.scope.'),
            'dateOrders' => $ambiguity->ambiguousDateOrder ? $this->enumOptions(DateOrder::cases(), 'import.date_order.') : [],
            'decimals' => $ambiguity->ambiguousDecimal ? $this->enumOptions(DecimalSeparator::cases(), 'import.decimal.') : [],
            'profile' => $import->profile()?->name(),
            'profileId' => $import->profile()?->id(),
            'profileName' => $profileName ?? '',
            'errors' => $errors,
            'invalid' => [] !== $errors || [] !== $columnErrors,
        ];
    }

    /**
     * Everything the check screen shows.
     *
     * @return array<string, mixed>
     */
    public function check(Import $import, ?string $outcome): array
    {
        $counts = [
            RowOutcome::Create->value => $import->created(),
            RowOutcome::Update->value => $import->updated(),
            RowOutcome::Restore->value => $import->restored(),
            RowOutcome::Skip->value => $import->skipped(),
            RowOutcome::Error->value => $import->failed(),
        ];
        $available = array_values(array_filter(self::PREVIEW_OUTCOMES, static fn (RowOutcome $candidate): bool => $counts[$candidate->value] > 0));
        $chosen = RowOutcome::tryFrom((string) $outcome);
        $chosen = \in_array($chosen, $available, true) ? $chosen : ($available[0] ?? null);

        $tabs = array_map(fn (RowOutcome $candidate): array => [
            'label' => $this->trans('import.check.tab.'.$candidate->value),
            'href' => $this->urls->generate('app_import_show', ['id' => $import->id(), 'outcome' => $candidate->value]),
            'count' => $counts[$candidate->value],
            'active' => $candidate === $chosen,
        ], $available);

        $restored = $import->restored();
        $kpis = [
            ['label' => $this->trans('import.check.kpi.create'), 'value' => $this->number($import->created())],
            ['label' => $this->trans('import.check.kpi.update'), 'value' => $this->number($import->updated() + $restored)] + ($restored > 0 ? ['note' => $this->trans('import.check.restored_note', ['count' => $restored])] : []),
            ['label' => $this->trans('import.check.kpi.skip'), 'value' => $this->number($import->skipped())],
            ['label' => $this->trans('import.check.kpi.error'), 'value' => $this->number($import->failed())] + ($import->failed() > 0 ? ['badge' => ['label' => $this->trans('import.check.kpi.error_badge'), 'tone' => 'amber']] : []),
        ];

        return [
            'kpis' => $kpis,
            'tabs' => $tabs,
            'outcome' => $chosen?->value,
            'preview' => $chosen instanceof RowOutcome ? $this->preview($import, $chosen) : null,
            'archive' => $import->needsArchiveConfirmation() ? $import->archiveCandidates() : 0,
            'unidentified' => $import->unidentified(),
            'hasErrors' => $import->failed() > 0 && null !== $import->fileKey(),
        ];
    }

    /**
     * What the progress frame shows.
     *
     * @return array<string, mixed>
     */
    public function progress(Import $import): array
    {
        $state = $import->state();
        $counted = \in_array($state, [ImportState::Checking, ImportState::Running], true) && $import->total() > 0;
        $phase = match ($state) {
            ImportState::Uploaded, ImportState::Reading => 'reading',
            ImportState::Checking => 'checking',
            ImportState::Queued => 'queued',
            ImportState::Running => 'running',
            ImportState::Undoing => 'undoing',
            default => 'done',
        };

        return [
            'active' => self::isBusy($state),
            'phase' => $phase,
            'label' => $this->trans('import.progress.'.$phase),
            'value' => $counted ? min($import->processed(), $import->total()) : 0,
            'max' => $counted ? $import->total() : null,
            'valueText' => $counted ? $this->trans('import.progress.rows', ['done' => min($import->processed(), $import->total()), 'total' => $import->total()]) : null,
            'cancellable' => \in_array($state, [ImportState::Uploaded, ImportState::Reading, ImportState::Checking, ImportState::Queued], true),
        ];
    }

    /**
     * Everything the summary shows.
     *
     * @return array<string, mixed>
     */
    public function summary(Import $import, string $locale): array
    {
        $state = $import->state();
        $pages = $this->pages->find($import->targetKey());
        $id = (int) $import->id();
        $rowsKept = null !== $import->fileKey();
        $link = static fn (RowOutcome $outcome, int $count): array => $rowsKept && $count > 0 && null !== ($href = $pages?->listUrl($id, $outcome)) ? ['href' => $href] : [];

        if (ImportState::Undone === $state) {
            $kpis = [
                ['label' => $this->trans('import.summary.kpi.reverted'), 'value' => $this->number($import->reverted())],
                ['label' => $this->trans('import.summary.kpi.changed'), 'value' => $this->number($import->changedAfterImport())] + $link(RowOutcome::ChangedAfterImport, $import->changedAfterImport()),
            ];
        } else {
            $restored = $import->restored();
            $kpis = [
                ['label' => $this->trans('import.summary.kpi.created'), 'value' => $this->number($import->created())] + $link(RowOutcome::Create, $import->created()),
                ['label' => $this->trans('import.summary.kpi.updated'), 'value' => $this->number($import->updated())] + $link(RowOutcome::Update, $import->updated()) + ($restored > 0 ? ['note' => $this->trans('import.check.restored_note', ['count' => $restored])] : []),
                ['label' => $this->trans('import.summary.kpi.archived'), 'value' => $this->number($import->archived())] + $link(RowOutcome::Archive, $import->archived()),
                ['label' => $this->trans('import.summary.kpi.failed'), 'value' => $this->number($import->failed())],
            ];
        }

        $finishedAt = $import->finishedAt();
        $details = [
            ['label' => $this->trans('import.summary.target'), 'value' => $this->targetLabel($import->targetKey())],
            ['label' => $this->trans('import.summary.mode'), 'value' => $this->trans('import.mode.'.$import->options()->mode->value.'.label')],
            ['label' => $this->trans('import.summary.actor'), 'value' => $this->users->findById($import->actorUserId())?->displayName() ?? '—'],
            ['label' => $this->trans('import.summary.started'), 'value' => $this->dateTime($import->startedAt() ?? $import->createdAt())],
            ['label' => $this->trans('import.summary.finished'), 'value' => $finishedAt instanceof \DateTimeImmutable ? $this->dateTime($finishedAt) : '—'],
        ];
        if ($this->ran($import)) {
            $details[] = ['label' => $this->trans('import.summary.skipped'), 'value' => $this->number($import->skipped())];
        }

        $deadline = $import->undoDeadline();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        return [
            'kpis' => $this->ran($import) || ImportState::Undone === $state ? $kpis : [],
            'message' => $this->outcomeMessage($import),
            'tone' => match ($state) {
                ImportState::Finished, ImportState::Undone => 'success',
                ImportState::FinishedWithErrors => 'warning',
                ImportState::Stopped, ImportState::Failed => 'error',
                default => 'info',
            },
            'details' => $details,
            'hasErrors' => $import->failed() > 0 && $rowsKept && $this->ran($import),
            'undoable' => null === $import->undoRefusal($now),
            'undoDeadline' => $deadline instanceof \DateTimeImmutable ? $deadline->format($this->trans('format.date')) : null,
            'undoExpired' => 'import.refused.undo_expired' === $import->undoRefusal($now),
            'listUrl' => $pages?->listUrl(),
            'listLabel' => $pages instanceof TargetPages ? $this->trans($pages->listLabelKey()) : null,
            'locale' => $locale,
        ];
    }

    /**
     * The settings the file was read with, and what can be changed.
     *
     * @return array<string, mixed>
     */
    private function source(Import $import, bool $edit): array
    {
        $settings = SourceSettings::fromArray($import->sourceSettings());
        $csv = SourceFormat::Csv === $settings->format;
        $items = [['label' => $this->trans('import.file.format'), 'value' => strtoupper($settings->format->value)]];
        if ($csv) {
            $items[] = ['label' => $this->trans('import.file.encoding'), 'value' => $settings->encoding instanceof Encoding ? $this->trans('import.encoding.'.$settings->encoding->value) : '—'];
            $items[] = ['label' => $this->trans('import.file.delimiter'), 'value' => null === $settings->delimiter ? '—' : $this->trans('import.delimiter.'.(self::DELIMITERS[$settings->delimiter] ?? 'other'))];
        } elseif (null !== $settings->sheet) {
            $items[] = ['label' => $this->trans('import.file.sheet'), 'value' => $settings->sheet];
        }
        $items[] = ['label' => $this->trans('import.file.header_row'), 'value' => $this->number($settings->headerRow ?? 1)];
        $items[] = ['label' => $this->trans('import.file.rows'), 'value' => $this->number($import->total())];

        $encodings = [];
        foreach (Encoding::cases() as $encoding) {
            $encodings[$encoding->value] = $this->trans('import.encoding.'.$encoding->value);
        }
        $delimiters = [];
        foreach (self::DELIMITERS as $delimiter => $name) {
            $delimiters[$delimiter] = $this->trans('import.delimiter.'.$name);
        }

        return [
            'items' => $items,
            'edit' => $edit,
            'csv' => $csv,
            'settings' => $settings,
            'encodings' => $encodings,
            'delimiters' => $delimiters,
        ];
    }

    /**
     * @param list<ImportRow> $rows
     *
     * @return array<int, list<string>> up to three non-empty cell texts by column
     */
    private function samples(array $rows): array
    {
        $samples = [];
        foreach ($rows as $row) {
            foreach ($row->raw() as $column => $cell) {
                $cell = trim($cell);
                if ('' !== $cell && \count($samples[$column] ?? []) < 3) {
                    $samples[$column][] = $cell;
                }
            }
        }

        return $samples;
    }

    /**
     * The fields of the target grouped for a select, required ones marked.
     *
     * @return array<string, string|array<string, string>>
     */
    private function fieldOptions(ImportTarget $target): array
    {
        $groups = [];
        foreach ($target->fields() as $field) {
            $label = $this->trans($field->labelKey);
            $groups[$field->group][$field->key] = $field->required ? $this->trans('import.mapping.required_field', ['field' => $label]) : $label;
        }
        $options = ['' => $this->trans('import.mapping.do_not_import')];
        foreach ($groups as $group => $fields) {
            $options[$this->trans('import.group.'.$target->key().'.'.('' === $group ? 'general' : $group))] = $fields;
        }

        return $options;
    }

    /**
     * Why the column was proposed for the field it is mapped to now; nothing once the user chose another field.
     *
     * @return array{label: string, tone: string}|null
     */
    private function reasonBadge(?ColumnProposal $proposal, ?string $value, ?string $presetKey): ?array
    {
        if (!$proposal instanceof ColumnProposal || MatchReason::None === $proposal->reason || $proposal->fieldKey !== $value) {
            return null;
        }
        if (MatchReason::Preset === $proposal->reason) {
            $preset = $this->presets->find($presetKey);
            $label = $preset instanceof SourcePreset ? $this->trans('import.source_name.'.$preset->key()) : $this->trans('import.reason.preset');

            return ['label' => $label, 'tone' => 'green'];
        }

        return ['label' => $this->trans('import.reason.'.$proposal->reason->value), 'tone' => MatchReason::SimilarName === $proposal->reason ? 'amber' : 'green'];
    }

    /**
     * Whether the dates and decimal numbers of the mapped columns can be read in more than one way.
     *
     * @param array<string, FieldDefinition> $fields
     * @param list<ImportRow>                $rows
     */
    private function ambiguity(Mapping $mapping, array $fields, array $rows): NormalisationOptions
    {
        $values = [];
        foreach ($mapping->columns as $column => $key) {
            $type = null === $key ? null : ($fields[$key] ?? null)?->type;
            if (FieldType::Date === $type || FieldType::Decimal === $type) {
                foreach ($rows as $row) {
                    $values[] = $row->raw()[$column] ?? '';
                }
            }
        }

        return NormalisationOptions::detect($values);
    }

    /**
     * The source values of choice and country columns the import does not know,
     * with what the user decided they mean.
     *
     * @param array<string, FieldDefinition> $fields
     *
     * @return list<array{field: string, label: string, options: array<string, string>, rows: list<array<string, mixed>>, shown: int, distinct: int}>
     */
    private function valueRows(Import $import, Mapping $mapping, array $fields): array
    {
        $columnsByField = [];
        foreach ($mapping->columns as $column => $key) {
            $field = null === $key ? null : ($fields[$key] ?? null);
            if ($field instanceof FieldDefinition && \in_array($field->type, [FieldType::Choice, FieldType::Country], true)) {
                $columnsByField[$field->key][] = $column;
            }
        }
        if ([] === $columnsByField) {
            return [];
        }
        $distinct = $this->imports->distinctValues($import, array_merge(...array_values($columnsByField)), self::VALUE_LIMIT);

        $sections = [];
        foreach ($columnsByField as $key => $columns) {
            $field = $fields[$key];
            $counts = [];
            $total = 0;
            foreach ($columns as $column) {
                foreach ($distinct[$column]['values'] ?? [] as $text => $count) {
                    $counts[$text] = ($counts[$text] ?? 0) + $count;
                }
                $total += $distinct[$column]['distinct'] ?? 0;
            }
            $map = $mapping->valueMap($key);
            $rows = [];
            foreach ($counts as $text => $count) {
                $text = (string) $text;
                if (!$this->normaliser->normalise($field, $text, new NormalisationOptions()) instanceof RowError) {
                    continue;
                }
                $index = \count($rows);
                $rows[] = [
                    'id' => 'value-'.$key.'-'.$index,
                    'name' => 'values['.$key.']['.$index.']',
                    'sourceName' => 'value_sources['.$key.']['.$index.']',
                    'source' => $text,
                    'label' => $text,
                    'samples' => [$this->trans('import.values.rows', ['count' => $count])],
                    'value' => $map[$text] ?? '',
                ];
            }
            if ([] === $rows) {
                continue;
            }
            $sections[] = [
                'field' => $key,
                'label' => $this->trans($field->labelKey),
                'options' => ['' => $this->trans('import.values.unassigned')] + $this->choiceOptions($import->targetKey(), $field),
                'rows' => $rows,
                'shown' => \count($counts),
                'distinct' => $total,
            ];
        }

        return $sections;
    }

    /**
     * @return array<string, string>
     */
    private function choiceOptions(string $targetKey, FieldDefinition $field): array
    {
        $options = [];
        if (FieldType::Country === $field->type) {
            foreach (Countries::CODES as $code) {
                $options[$code] = Countries::name($code, $this->translator->getLocale());
            }
            asort($options, \SORT_LOCALE_STRING);

            return $options;
        }
        foreach ($field->choices as $choice) {
            $key = 'import.value.'.$targetKey.'.'.$field->key.'.'.$choice;
            $label = $this->trans($key);
            $options[$choice] = $label === $key ? $choice : $label;
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string, text: string, warning?: string}>
     */
    private function modes(): array
    {
        $modes = [];
        foreach (ImportMode::cases() as $mode) {
            $prefix = 'import.mode.'.$mode->value;
            $modes[] = ['value' => $mode->value, 'label' => $this->trans($prefix.'.label'), 'text' => $this->trans($prefix.'.text')]
                + (ImportMode::FullReplace === $mode ? ['warning' => $this->trans($prefix.'.warning')] : []);
        }

        return $modes;
    }

    /**
     * @return array<string, string>
     */
    private function matchKeyOptions(ImportTarget $target): array
    {
        $fields = $this->fieldsByKey($target);
        $options = ['' => $this->trans('import.options.match_key_auto')];
        foreach ($target->matchKeys() as $key) {
            if (isset($fields[$key])) {
                $options[$key] = $this->trans($fields[$key]->labelKey);
            }
        }

        return $options;
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, string>
     */
    private function enumOptions(array $cases, string $prefix): array
    {
        $options = [];
        foreach ($cases as $case) {
            $options[(string) $case->value] = $this->trans($prefix.$case->value);
        }

        return $options;
    }

    /**
     * @return array<string, FieldDefinition>
     */
    private function fieldsByKey(ImportTarget $target): array
    {
        $fields = [];
        foreach ($target->fields() as $field) {
            $fields[$field->key] = $field;
        }

        return $fields;
    }

    /**
     * The first rows of one outcome: the row number, a few mapped values and, for errors, the messages.
     *
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, errors: list<array{row: string, title: string, messages: list<string>}>, more: bool}
     */
    private function preview(Import $import, RowOutcome $outcome): array
    {
        $target = $this->targets->get($import->targetKey());
        $fields = $this->fieldsByKey($target);
        $headers = $import->headers();
        $mapping = $import->mapping();
        $mapped = array_filter($mapping->columns, static fn (?string $key): bool => null !== $key && isset($fields[$key]));
        $shown = \array_slice($mapped, 0, self::PREVIEW_COLUMNS, true);
        $errors = RowOutcome::Error === $outcome;

        $columns = [['key' => 'row', 'label' => $this->trans('import.check.column_row'), 'align' => 'right']];
        foreach (array_keys($shown) as $column) {
            $columns[] = ['key' => 'c'.$column, 'label' => $headers[$column] ?? (string) $column];
        }
        $columnOf = array_flip(array_map(strval(...), $mapped));

        $rows = [];
        $failures = [];
        $found = $this->imports->previewRows($import, $outcome, self::PREVIEW_ROWS);
        foreach ($found as $row) {
            if ($errors) {
                // Messages are sentences; they are listed in full rather than cut off in a table cell.
                $first = array_key_first($shown);
                $failures[] = [
                    'row' => $this->trans('import.check.row', ['number' => $row->rowNumber()]),
                    'title' => null === $first ? '' : trim($row->raw()[$first] ?? ''),
                    'messages' => array_map(fn (RowError $error): string => $this->errorText($error, $headers, $columnOf), $row->errors()),
                ];
                continue;
            }
            $cells = ['row' => $this->number($row->rowNumber())];
            foreach (array_keys($shown) as $column) {
                $cells['c'.$column] = trim($row->raw()[$column] ?? '');
            }
            $rows[] = ['id' => (string) $row->rowNumber(), 'cells' => $cells];
        }
        $count = match ($outcome) {
            RowOutcome::Create => $import->created(),
            RowOutcome::Update => $import->updated(),
            RowOutcome::Restore => $import->restored(),
            RowOutcome::Skip => $import->skipped(),
            default => $import->failed(),
        };

        return ['columns' => $columns, 'rows' => $rows, 'errors' => $failures, 'more' => $count > \count($found)];
    }

    /**
     * @param list<string>       $headers
     * @param array<string, int> $columnOf column by field key
     */
    private function errorText(RowError $error, array $headers, array $columnOf): string
    {
        $message = $this->trans($error->messageKey, $error->parameters);
        $column = $columnOf[$error->field] ?? null;

        return null === $column ? $message : $this->trans('import.error_file.in_column', ['column' => $headers[$column] ?? '', 'message' => $message]);
    }

    private function outcomeMessage(Import $import): string
    {
        $state = $import->state();
        if (ImportState::Failed === $state) {
            $key = $import->failureMessageKey();
            $reason = null === $key || 'import.source.rejected.invalid_encoding' === $key ? 'import.summary.failure_unreadable' : $key;

            return $this->trans('import.summary.outcome.failed').' '.$this->trans($reason, self::FAILURE_PARAMETERS[$reason] ?? []);
        }

        return $this->trans('import.summary.outcome.'.$state->value, ['count' => $import->failed()]);
    }

    /** Whether the import got as far as a run, so its counters say what it wrote. */
    private function ran(Import $import): bool
    {
        return $import->startedAt() instanceof \DateTimeImmutable;
    }

    private function dateTime(\DateTimeImmutable $at): string
    {
        return $at->format($this->trans('format.datetime'));
    }

    private function number(int $value): string
    {
        return $this->trans('import.number', ['value' => $value]);
    }

    /**
     * @return list<string> the names of a field in every language a header may be written in
     */
    private function labels(string $labelKey): array
    {
        return array_values(array_unique(array_map(fn (string $locale): string => $this->translator->trans($labelKey, [], 'messages', $locale), self::LABEL_LOCALES)));
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }
}
