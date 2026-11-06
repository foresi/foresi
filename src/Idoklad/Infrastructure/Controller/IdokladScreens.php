<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Infrastructure\Controller;

use App\Idoklad\Application\IdokladSnapshot;
use App\Idoklad\Application\Import\RunIdokladImport;
use App\Idoklad\Domain\IdokladImportRun;
use App\Idoklad\Domain\IdokladJob;
use App\Idoklad\Domain\IdokladJobState;
use App\Idoklad\Domain\IdokladPull;
use App\Idoklad\Domain\IdokladRecord;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportOutcome;
use App\Invoicing\Application\DocumentFormat;
use App\Party\Domain\DocumentLanguage;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the screens of the move from iDoklad show, worked out from the jobs, the
 * pulls, the import runs and the snapshot: the step indicator, the state of a
 * pull, and the report of a check or a run with its sums by year.
 */
final readonly class IdokladScreens
{
    /** The documents whose totals are summed by year. */
    private const array DOCUMENTS = [IdokladResource::IssuedInvoices, IdokladResource::ProformaInvoices, IdokladResource::IssuedTaxDocuments, IdokladResource::CreditNotes];

    /** What each stage reads, for the records of its report. */
    private const array STAGE_RESOURCES = [
        'contacts' => IdokladResource::Contacts,
        'series' => IdokladResource::NumericSequences,
        'payments' => IdokladResource::IssuedDocumentPayments,
        'recurring' => IdokladResource::RecurringInvoices,
    ];

    /** Values a refusal is explained with, by their labels; the rest are technical. */
    private const array SHOWN_VALUES = ['currency', 'payment_option', 'name', 'number_format', 'year', 'last_number', 'series_format', 'next_issue', 'idoklad_next_issue'];

    private const array TOTALS = ['net', 'vat', 'gross', 'payable'];

    /** Outcomes of what is in Foresi after the run. */
    private const array IMPORTED = [ImportOutcome::Created->value, ImportOutcome::Matched->value, ImportOutcome::AlreadyLinked->value];

    public function __construct(
        private IdokladSnapshot $snapshot,
        private RunIdokladImport $import,
        private DocumentFormat $format,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{steps: list<string>, current: int}
     */
    public function stepper(int $current): array
    {
        return ['steps' => array_map(fn (string $step): string => $this->trans('idoklad.steps.'.$step), ['connect', 'pull', 'check', 'run', 'result']), 'current' => $current];
    }

    /**
     * @return list<array{label: string, href?: string}>
     */
    public function crumbs(LegalEntity $firm, ?string $last = null): array
    {
        $crumbs = [
            ['label' => $this->trans('import.history.title'), 'href' => $this->urls->generate('app_imports')],
            ['label' => $this->trans('idoklad.title'), 'href' => $this->urls->generate('app_idoklad', ['id' => $firm->id()])],
            ['label' => $firm->name()],
        ];
        if (null !== $last) {
            $crumbs[2]['href'] = $this->urls->generate('app_idoklad', ['id' => $firm->id()]);
            $crumbs[] = ['label' => $last];
        }

        return $crumbs;
    }

    /**
     * @return array{label: string, tone: string}
     */
    public function jobBadge(IdokladJobState $state): array
    {
        return [
            'label' => $this->trans('idoklad.job.state.'.$state->value),
            'tone' => match ($state) {
                IdokladJobState::Done => 'green',
                IdokladJobState::Failed => 'red',
                IdokladJobState::Pending => 'amber',
                IdokladJobState::Running => 'gray',
            },
        ];
    }

    /**
     * The step a busy job is at.
     */
    public function stepOf(IdokladJob $job): int
    {
        return match (true) {
            $job->kind()->pulls() => 1,
            'check' === $job->kind()->value => 2,
            default => 3,
        };
    }

    /**
     * The latest pull: its state, its records by list and the requests of the month.
     *
     * @param list<IdokladPull> $pulls the latest first
     *
     * @return array<string, mixed>|null
     */
    public function pull(array $pulls, \DateTimeImmutable $now): ?array
    {
        $latest = $pulls[0] ?? null;
        if (!$latest instanceof IdokladPull) {
            return null;
        }
        $month = $now->format('Y-m');
        $requests = 0;
        foreach ($pulls as $pull) {
            if ($pull->startedAt()->format('Y-m') === $month) {
                $requests += $pull->requestsUsed();
            }
        }
        $rows = [];
        foreach ($latest->counts() as $resource => $count) {
            $rows[] = ['id' => $resource, 'cells' => ['list' => $this->trans('idoklad.resource.'.$resource), 'records' => $this->number($count)]];
        }

        return [
            'state' => match (true) {
                !$latest->finishedAt() instanceof \DateTimeImmutable => $this->jobBadge(IdokladJobState::Running),
                $latest->succeeded() => $this->jobBadge(IdokladJobState::Done),
                default => $this->jobBadge(IdokladJobState::Failed),
            },
            'error' => $latest->error(),
            'delta' => null !== $latest->changedSince(),
            'items' => [
                ['label' => $this->trans('idoklad.pull.started'), 'value' => $this->dateTime($latest->startedAt())],
                ['label' => $this->trans('idoklad.pull.kind'), 'value' => $this->trans(null !== $latest->changedSince() ? 'idoklad.pull.kind_changes' : 'idoklad.pull.kind_full')],
                ['label' => $this->trans('idoklad.pull.requests'), 'value' => $this->number($latest->requestsUsed())],
            ],
            'requestsMonth' => $requests,
            'columns' => [
                ['key' => 'list', 'label' => $this->trans('idoklad.pull.column.list')],
                ['key' => 'records', 'label' => $this->trans('idoklad.pull.column.records'), 'align' => 'right'],
            ],
            'rows' => $rows,
        ];
    }

    /**
     * The report of a check or a run: the outcomes by stage, the documents and
     * their sums by year in iDoklad and in Foresi, what was refused or differs,
     * and the next issues of the templates.
     *
     * @return array<string, mixed>
     */
    public function report(IdokladImportRun $run, string $locale, \DateTimeImmutable $today): array
    {
        $language = DocumentLanguage::tryFrom($locale) ?? DocumentLanguage::Czech;
        $firm = $run->firm();
        $outcomes = array_map(static fn (ImportOutcome $outcome): string => $outcome->value, ImportOutcome::cases());
        $stageRows = [];
        $totals = array_fill_keys($outcomes, 0);
        foreach ($this->import->stages() as $stage) {
            $counts = $run->counts()[$stage] ?? [];
            $cells = ['stage' => $this->trans('idoklad.stage.'.$stage)];
            foreach ($outcomes as $outcome) {
                $cells[$outcome] = $this->number($counts[$outcome] ?? 0);
                $totals[$outcome] += $counts[$outcome] ?? 0;
            }
            $stageRows[] = ['id' => $stage, 'cells' => $cells];
        }
        $stageColumns = [['key' => 'stage', 'label' => $this->trans('idoklad.report.column.stage')]];
        foreach ($outcomes as $outcome) {
            $stageColumns[] = ['key' => $outcome, 'label' => $this->trans('idoklad.outcome.'.$outcome), 'align' => 'right'];
        }

        // What the documents stage brought, or would bring, by list and id.
        $imported = [];
        $groups = ['refused' => [], 'mismatch' => []];
        $templates = [];
        foreach ($run->report() as $entry) {
            $resource = 'documents' === $entry['stage'] ? IdokladResource::tryFrom((string) ($entry['values']['resource'] ?? '')) : (self::STAGE_RESOURCES[$entry['stage']] ?? null);
            if ('documents' === $entry['stage'] && $resource instanceof IdokladResource && \in_array($entry['outcome'], self::IMPORTED, true)) {
                $imported[$resource->value][$entry['external_id']] = true;
            }
            if ('recurring' === $entry['stage'] && ImportOutcome::Created->value === $entry['outcome']) {
                $record = $this->record($firm, $resource, $entry['external_id']);
                $templates[] = [
                    'title' => $this->title($record, $entry['values'], $entry['external_id']),
                    'dates' => array_map(fn (string $date): string => $this->format->date(new \DateTimeImmutable($date), $language), array_values(array_filter(explode(',', (string) ($entry['values']['next_issues'] ?? '')), static fn (string $date): bool => '' !== $date))),
                ];
            }
            if (!isset($groups[$entry['outcome']])) {
                continue;
            }
            $record = $this->record($firm, $resource, $entry['external_id']);
            $groups[$entry['outcome']][$entry['stage']][] = [
                'title' => $this->title($record, $entry['values'], $entry['external_id']),
                'customer' => $this->customer($record),
                'reasons' => array_map(fn (string $reason): string => $this->trans($reason), $entry['reasons']),
                'values' => ImportOutcome::Mismatch->value === $entry['outcome'] ? $this->differences($entry['values'], $language) : $this->explained($entry['values']),
            ];
        }

        $years = [];
        $overdue = 0;
        foreach (self::DOCUMENTS as $resource) {
            foreach ($this->snapshot->records($firm, $resource) as $record) {
                $payload = $record->payload();
                $year = substr(\is_string($payload['DateOfIssue'] ?? null) ? $payload['DateOfIssue'] : '', 0, 4);
                $total = $this->minor(\is_array($payload['Prices'] ?? null) ? ($payload['Prices']['TotalWithVat'] ?? null) : null);
                $years[$year] ??= ['idoklad' => 0, 'idokladSum' => 0, 'foresi' => 0, 'foresiSum' => 0];
                ++$years[$year]['idoklad'];
                $years[$year]['idokladSum'] += $total;
                if (!isset($imported[$resource->value][$record->externalId()])) {
                    continue;
                }
                ++$years[$year]['foresi'];
                $years[$year]['foresiSum'] += $total;
                if (IdokladResource::IssuedInvoices === $resource && $this->isOverdue($payload, $total, $today)) {
                    ++$overdue;
                }
            }
        }
        krsort($years);
        $yearRows = [];
        foreach ($years as $year => $sums) {
            $yearRows[] = ['id' => 'y'.$year, 'cells' => [
                'year' => '' === $year ? '—' : $year,
                'idoklad' => $this->number($sums['idoklad']),
                'idokladSum' => $this->format->money(Money::ofMinor($sums['idokladSum'], Currency::CZK), $language),
                'foresi' => $this->number($sums['foresi']),
                'foresiSum' => $this->format->money(Money::ofMinor($sums['foresiSum'], Currency::CZK), $language),
            ]];
        }

        return [
            // The strip holds four: what was linked before is in the table by section.
            'kpis' => array_map(fn (string $outcome): array => ['label' => $this->trans('idoklad.outcome.'.$outcome), 'value' => $this->number($totals[$outcome])], array_values(array_diff($outcomes, [ImportOutcome::AlreadyLinked->value]))),
            'stageColumns' => $stageColumns,
            'stageRows' => $stageRows,
            'yearColumns' => array_map(fn (string $key): array => ['key' => $key, 'label' => $this->trans('idoklad.report.column.'.$key), 'align' => 'year' === $key ? 'left' : 'right'], ['year', 'idoklad', 'idokladSum', 'foresi', 'foresiSum']),
            'yearRows' => $yearRows,
            'refused' => $this->labelled($groups['refused']),
            'mismatch' => $this->labelled($groups['mismatch']),
            'templates' => $templates,
            'created' => $totals[ImportOutcome::Created->value],
            'refusedCount' => $totals[ImportOutcome::Refused->value],
            'mismatchCount' => $totals[ImportOutcome::Mismatch->value],
            'overdue' => $overdue,
            'error' => $run->error(),
        ];
    }

    /**
     * The history of a firm: its pulls and import runs, the latest first.
     *
     * @param list<IdokladPull>      $pulls
     * @param list<IdokladImportRun> $runs
     *
     * @return list<array<string, mixed>>
     */
    public function history(LegalEntity $firm, array $pulls, array $runs): array
    {
        $rows = [];
        foreach ($pulls as $pull) {
            $state = $pull->finishedAt() instanceof \DateTimeImmutable ? $pull->succeeded() ? IdokladJobState::Done : IdokladJobState::Failed : (IdokladJobState::Running);
            $rows[] = ['at' => $pull->startedAt(), 'row' => [
                'id' => $pull->id(),
                'label' => $this->trans(null !== $pull->changedSince() ? 'idoklad.pull.kind_changes' : 'idoklad.pull.kind_full'),
                'cells' => [
                    'when' => $this->dateTime($pull->startedAt()),
                    'what' => $this->trans(null !== $pull->changedSince() ? 'idoklad.pull.kind_changes' : 'idoklad.pull.kind_full'),
                    'records' => $this->number(array_sum($pull->counts())),
                    'requests' => $this->number($pull->requestsUsed()),
                    'state' => $this->jobBadge($state) + ['hint' => $pull->error()],
                ],
            ]];
        }
        foreach ($runs as $run) {
            $state = null === $run->error() ? IdokladJobState::Done : IdokladJobState::Failed;
            $badge = $this->jobBadge($state) + ['hint' => $run->error()];
            if ($run->undoneAt() instanceof \DateTimeImmutable) {
                $badge = ['label' => $this->trans('idoklad.run.undone'), 'tone' => 'gray'];
            }
            $label = $this->trans('idoklad.run.mode.'.$run->mode()->value);
            $rows[] = ['at' => $run->startedAt(), 'row' => [
                'id' => $run->id(),
                'href' => $this->urls->generate('app_idoklad_run', ['id' => $firm->id(), 'run' => $run->id()]),
                'label' => $label,
                'cells' => [
                    'when' => $this->dateTime($run->startedAt()),
                    'what' => $label,
                    'records' => $this->number(array_sum(array_map(array_sum(...), $run->counts()))),
                    'requests' => null,
                    'state' => $badge,
                ],
            ]];
        }
        usort($rows, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_map(static fn (array $row): array => $row['row'], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyColumns(): array
    {
        return array_map(fn (string $key): array => ['key' => $key, 'label' => $this->trans('idoklad.history.column.'.$key), 'align' => \in_array($key, ['records', 'requests'], true) ? 'right' : 'left'], ['when', 'what', 'records', 'requests', 'state']);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $byStage
     *
     * @return list<array{stage: string, label: string, records: list<array<string, mixed>>}>
     */
    private function labelled(array $byStage): array
    {
        $groups = [];
        foreach ($byStage as $stage => $records) {
            $groups[] = ['stage' => $stage, 'label' => $this->trans('idoklad.stage.'.$stage), 'records' => $records];
        }

        return $groups;
    }

    private function record(LegalEntity $firm, ?IdokladResource $resource, int $externalId): ?IdokladRecord
    {
        return $resource instanceof IdokladResource ? $this->snapshot->record($firm, $resource, $externalId) : null;
    }

    /**
     * @param array<string, int|string|bool|null> $values
     */
    private function title(?IdokladRecord $record, array $values, int $externalId): string
    {
        $payload = $record?->payload() ?? [];
        foreach ([$values['document_number'] ?? null, $payload['DocumentNumber'] ?? null, $payload['CompanyName'] ?? null, $payload['Name'] ?? null] as $candidate) {
            if (\is_string($candidate) && '' !== trim($candidate)) {
                return $candidate;
            }
        }

        return $this->trans('idoklad.report.record', ['id' => $externalId]);
    }

    private function customer(?IdokladRecord $record): string
    {
        $address = $record?->payload()['PartnerAddress'] ?? null;
        $name = \is_array($address) ? ($address['NickName'] ?? null) : null;

        return \is_string($name) ? $name : '';
    }

    /**
     * Expected against computed, for each total that differs.
     *
     * @param array<string, int|string|bool|null> $values
     *
     * @return list<string>
     */
    private function differences(array $values, DocumentLanguage $language): array
    {
        $lines = [];
        foreach (self::TOTALS as $total) {
            $expected = $values['expected_'.$total] ?? null;
            $computed = $values['computed_'.$total] ?? null;
            if (\is_int($expected) && \is_int($computed) && $expected !== $computed) {
                $lines[] = $this->trans('idoklad.report.difference', [
                    'total' => $this->trans('idoklad.report.total.'.$total),
                    'expected' => $this->format->money(Money::ofMinor($expected, Currency::CZK), $language),
                    'computed' => $this->format->money(Money::ofMinor($computed, Currency::CZK), $language),
                ]);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, int|string|bool|null> $values
     *
     * @return list<string>
     */
    private function explained(array $values): array
    {
        $lines = [];
        foreach (self::SHOWN_VALUES as $key) {
            $value = $values[$key] ?? null;
            if (\is_int($value) || (\is_string($value) && '' !== $value)) {
                $lines[] = $this->trans('idoklad.value.'.$key).': '.$value;
            }
        }

        return $lines;
    }

    /**
     * @param array<mixed> $payload
     */
    private function isOverdue(array $payload, int $total, \DateTimeImmutable $today): bool
    {
        $due = $payload['DateOfMaturity'] ?? null;
        $paid = $this->minor(\is_array($payload['Prices'] ?? null) ? ($payload['Prices']['TotalPaid'] ?? null) : null);

        return \is_string($due) && substr($due, 0, 10) < $today->format('Y-m-d') && $paid < $total;
    }

    private function minor(mixed $amount): int
    {
        return \is_int($amount) || \is_float($amount) ? (int) round($amount * 100) : 0;
    }

    private function dateTime(\DateTimeImmutable $at): string
    {
        return $at->format($this->trans('format.datetime'));
    }

    private function number(int $value): string
    {
        return $this->trans('import.number', ['value' => $value]);
    }
}
