<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application\Import;

use App\Idoklad\Domain\IdokladNumberFormat;
use App\Idoklad\Domain\IdokladResource;
use App\Idoklad\Domain\ImportOutcome;
use App\Invoicing\Application\NumberCounter;
use App\Invoicing\Application\SeriesCatalog;
use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\InvalidSeriesFormat;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;

/**
 * iDoklad numeric sequences as Foresi series. iDoklad keeps a sequence per
 * year; the years of one format are one series here, which counts per year
 * when its format has a year. A series of the same kind whose numbers look the
 * same is used; else one is created, the default of its kind when iDoklad's is
 * and the firm has none. The counter of the year continues after iDoklad's
 * last number and never goes back. Sequences of documents Foresi does not
 * issue are refused.
 */
final readonly class SeriesStage implements ImportStage
{
    public const string KEY = 'series';
    public const string UNKNOWN_TYPE = 'idoklad.import.series.unknown_type';
    public const string INVALID_FORMAT = 'idoklad.import.series.invalid_format';
    public const string INVALID_COUNTER = 'idoklad.import.series.invalid_counter';
    public const string NAME_TAKEN = 'idoklad.import.series.name_taken';

    /** The document types of iDoklad by their code, as the kinds of Foresi. */
    private const array KINDS = [
        0 => DocumentKind::Invoice,
        1 => DocumentKind::Advance,
        3 => DocumentKind::CorrectionDocument,
        10 => DocumentKind::PaymentTaxDocument,
    ];

    public function __construct(
        private SeriesCatalog $catalog,
        private NumberCounter $counter,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function order(): int
    {
        return 200;
    }

    public function run(ImportContext $context): void
    {
        foreach ($context->records(IdokladResource::NumericSequences) as $record) {
            $id = $record->externalId();
            $payload = $record->payload();
            $values = [
                'document_type' => \is_int($payload['DocumentType'] ?? null) ? $payload['DocumentType'] : null,
                'number_format' => \is_string($payload['NumberFormat'] ?? null) ? $payload['NumberFormat'] : null,
                'year' => \is_int($payload['Year'] ?? null) ? $payload['Year'] : null,
                'last_number' => \is_int($payload['LastNumber'] ?? null) ? $payload['LastNumber'] : null,
            ];
            $kind = self::KINDS[$values['document_type'] ?? -1] ?? null;
            if (!$kind instanceof DocumentKind) {
                $context->report(self::KEY, $id, ImportOutcome::Refused, reasons: [self::UNKNOWN_TYPE], values: $values);

                continue;
            }
            $format = $this->format($values['number_format'] ?? '');
            if (!$format instanceof SeriesFormat) {
                $context->report(self::KEY, $id, ImportOutcome::Refused, reasons: [self::INVALID_FORMAT], values: $values);

                continue;
            }
            $year = $values['year'];
            $last = $values['last_number'];
            if (null === $year || $year < 1 || $year > 9999 || null === $last || $last < 0 || $last > SeriesFormat::MAX_SEQUENCE) {
                $context->report(self::KEY, $id, ImportOutcome::Refused, reasons: [self::INVALID_COUNTER], values: $values);

                continue;
            }

            $linked = $context->linkedTo(IdokladResource::NumericSequences, $id);
            if (null !== $linked) {
                $series = $this->catalog->get($linked);
                if (!$series->format()->collidesWith($format)) {
                    $context->report(self::KEY, $id, ImportOutcome::Mismatch, $linked, values: $values + ['series_format' => $series->format()->toString()]);

                    continue;
                }
                $outcome = ImportOutcome::AlreadyLinked;
            } else {
                $series = $this->same($context, $kind, $format);
                $outcome = ImportOutcome::Matched;
                if (!$series instanceof Series) {
                    $series = $this->create($context, $kind, $format, \is_string($payload['Name'] ?? null) ? $payload['Name'] : '', true === ($payload['IsDefault'] ?? null));
                    $outcome = ImportOutcome::Created;
                }
                if (!$series instanceof Series) {
                    $context->report(self::KEY, $id, ImportOutcome::Refused, reasons: [self::NAME_TAKEN], values: $values + ['name' => \is_string($payload['Name'] ?? null) ? $payload['Name'] : '']);

                    continue;
                }
                $context->link(IdokladResource::NumericSequences, $id, $series->id());
            }

            $date = new \DateTimeImmutable(\sprintf('%04d-01-01', $year));
            $this->counter->advanceTo($series, $date, $last);
            $context->report(self::KEY, $id, $outcome, $series->id(), values: $values + ['next_number' => $this->counter->peek($series, $date)]);
        }
    }

    private function format(string $numberFormat): ?SeriesFormat
    {
        $format = IdokladNumberFormat::toSeries($numberFormat);
        if (null === $format) {
            return null;
        }

        try {
            return SeriesFormat::fromString($format);
        } catch (InvalidSeriesFormat) {
            return null;
        }
    }

    /**
     * An active series of the kind whose numbers are the numbers of the format.
     */
    private function same(ImportContext $context, DocumentKind $kind, SeriesFormat $format): ?Series
    {
        foreach ($this->catalog->forFirm($context->firm, $kind) as $series) {
            if ($series->format()->collidesWith($format)) {
                return $series;
            }
        }

        return null;
    }

    /**
     * A new series under iDoklad's name; null when the firm has a series of that name, archived ones too.
     */
    private function create(ImportContext $context, DocumentKind $kind, SeriesFormat $format, string $name, bool $default): ?Series
    {
        $name = '' === trim($name) ? $kind->standardSeriesName() : trim($name);
        foreach ($this->catalog->everyForFirm($context->firm) as $series) {
            if (mb_strtolower($series->name()) === mb_strtolower($name)) {
                return null;
            }
        }
        $series = new Series($context->firm, $name, $format, $kind);
        $this->catalog->store($series);
        if ($default && !array_any($this->catalog->forFirm($context->firm, $kind), static fn (Series $other): bool => $other->isDefault())) {
            $this->catalog->makeDefault($series);
        }

        return $series;
    }
}
