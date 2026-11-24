<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

use Foresi\Purchasing\Application\DocumentLock;
use Foresi\Purchasing\Application\ReceivedDocumentWarning;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\Reading;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentContent;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Purchasing\Domain\VatRate;
use Foresi\Purchasing\Domain\VatRecapLine;
use Foresi\Purchasing\Domain\VatRecapRow;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * Fills a draft with what a reading found, only while nobody has touched it:
 * the draft has the version the reading was asked for, is in no trash, is not
 * recorded and not locked. Otherwise every value stays a suggestion.
 *
 * The draft is saved with saveDraft(), so the document decides what fits. When
 * it refuses, the groups of fields are left out in turn and the rest is tried
 * again: the amounts first, then the dates, then the kind. What is left out
 * becomes a suggestion with the warning READING_PARTLY_APPLIED. Amounts whose
 * VAT recap does not add up to the total are left out the same way, though a
 * draft typed by a person may differ by its rounding: a structured reading
 * states both exactly.
 *
 * The supplier, its identity and the bank account are never written into the
 * draft from here; they go through supplier matching or stay in the reading.
 * The treatment is the draft's: a reading does not say it.
 */
final readonly class ReadingApplication
{
    /** The groups of fields a draft takes, in the order they are left out when it refuses them. */
    private const array AMOUNTS = [ReadField::Currency, ReadField::Total, ReadField::VatRecap];
    private const array DATES = [ReadField::IssueDate, ReadField::TaxPointDate, ReadField::DueDate];
    private const array KIND = [ReadField::Kind];
    private const array TEXTS = [ReadField::SupplierNumber, ReadField::VariableSymbol];

    public function __construct(private DocumentLock $lock)
    {
    }

    /**
     * @param int $baseVersion the version of the document the reading was asked for
     */
    public function apply(ReceivedDocument $document, Reading $merged, int $baseVersion): AppliedReading
    {
        $fields = $this->applicable($merged);
        if ([] === $fields) {
            return new AppliedReading([], [], []);
        }
        if (!$this->untouched($document, $baseVersion)) {
            return new AppliedReading([], $fields, []);
        }
        $left = [];
        foreach ([[], self::AMOUNTS, self::DATES, self::KIND] as $group) {
            if ([] !== $group && [] === array_intersect($this->keys($group), $this->keys($fields))) {
                continue;
            }
            $left = [...$left, ...$group];
            $taken = array_values(array_filter($fields, static fn (ReadField $field): bool => !\in_array($field, $left, true)));
            if ([] === $taken) {
                break;
            }
            if ($this->save($document, $merged, $taken)) {
                $suggested = array_values(array_filter($fields, static fn (ReadField $field): bool => !\in_array($field, $taken, true)));

                return new AppliedReading($taken, $suggested, [] === $suggested ? [] : [ReceivedDocumentWarning::READING_PARTLY_APPLIED]);
            }
        }

        return new AppliedReading([], $fields, [ReceivedDocumentWarning::READING_PARTLY_APPLIED]);
    }

    private function untouched(ReceivedDocument $document, int $baseVersion): bool
    {
        return !$document->isRecorded() && !$document->isTrashed() && $document->version() === $baseVersion && !$this->lock->isLocked($document);
    }

    /**
     * The fields of the reading a draft takes, in the order of the fields; a
     * value of the wrong shape is left in the reading only.
     *
     * @return list<ReadField>
     */
    private function applicable(Reading $merged): array
    {
        $groups = [...self::AMOUNTS, ...self::DATES, ...self::KIND, ...self::TEXTS];
        $fields = [];
        foreach (ReadField::cases() as $field) {
            if (\in_array($field, $groups, true) && $this->shaped($merged, $field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private function shaped(Reading $merged, ReadField $field): bool
    {
        $value = $merged->get($field)?->value;

        return match ($field) {
            ReadField::Kind => \is_string($value) && null !== ReceivedDocumentKind::tryFrom($value),
            ReadField::IssueDate, ReadField::TaxPointDate, ReadField::DueDate => null !== self::date($value),
            ReadField::Currency => \is_string($value) && null !== Currency::tryFrom($value),
            ReadField::Total => \is_int($value),
            ReadField::VatRecap => null !== self::recap($value),
            default => \is_string($value),
        };
    }

    /**
     * Saves the draft with these fields of the reading over what it holds.
     *
     * @param list<ReadField> $taken
     */
    private function save(ReceivedDocument $document, Reading $merged, array $taken): bool
    {
        try {
            $content = $this->content($document, $merged, $taken);
            if (!$content instanceof ReceivedDocumentContent) {
                return false;
            }
            // A refused save changes nothing on the document.
            $document->saveDraft($content);

            return true;
        } catch (ReceivedDocumentRefused) {
            return false;
        }
    }

    /**
     * @param list<ReadField> $taken
     *
     * @return ReceivedDocumentContent|null null for amounts that do not add up
     *
     * @throws ReceivedDocumentRefused for a VAT rate that is none
     */
    private function content(ReceivedDocument $document, Reading $merged, array $taken): ?ReceivedDocumentContent
    {
        $value = static fn (ReadField $field): mixed => \in_array($field, $taken, true) ? $merged->get($field)?->value : null;
        $date = static fn (ReadField $field, ?\DateTimeImmutable $current): ?\DateTimeImmutable => \in_array($field, $taken, true) ? self::date($value($field)) : $current;

        $kindValue = $value(ReadField::Kind);
        $kind = \is_string($kindValue) ? (ReceivedDocumentKind::tryFrom($kindValue) ?? $document->kind()) : $document->kind();
        $currencyValue = $value(ReadField::Currency);
        $currency = \is_string($currencyValue) ? (Currency::tryFrom($currencyValue) ?? $document->currency()) : $document->currency();
        // A credit note subtracts; the formats write its amounts as positive.
        $sign = static fn (int $minor): int => ReceivedDocumentKind::CreditNote === $kind ? -abs($minor) : $minor;

        $totalValue = $value(ReadField::Total);
        $total = \is_int($totalValue) ? Money::ofMinor($sign($totalValue), $currency) : $document->total();
        $rows = self::recap($value(ReadField::VatRecap));
        if (null !== $rows && \is_int($totalValue) && array_sum(array_map(static fn (array $row): int => $row['baseMinor'] + $row['vatMinor'], $rows)) !== $totalValue) {
            return null;
        }
        $recap = null === $rows
            ? array_map(static fn (VatRecapRow $row): VatRecapLine => new VatRecapLine($row->rate, $row->base, $row->vat, $row->statedVatCzk), $document->vatRecap()->rows())
            : array_map(static fn (array $row): VatRecapLine => new VatRecapLine(VatRate::of($row['rate']), Money::ofMinor($sign($row['baseMinor']), $currency), Money::ofMinor($sign($row['vatMinor']), $currency)), $rows);
        // The rate of another currency does not carry over; recording takes the published one.
        $sameCurrency = $currency === $document->currency();
        $supplierNumber = $value(ReadField::SupplierNumber);
        $variableSymbol = $value(ReadField::VariableSymbol);

        return new ReceivedDocumentContent(
            $kind,
            $document->treatment(),
            $document->supplierParty(),
            $document->supplier(),
            \is_string($supplierNumber) ? $supplierNumber : $document->supplierNumber(),
            \is_string($variableSymbol) ? $variableSymbol : $document->variableSymbol(),
            $date(ReadField::IssueDate, $document->issueDate()),
            $date(ReadField::TaxPointDate, $document->taxPointDate()),
            $date(ReadField::DueDate, $document->dueDate()),
            $document->receivedDate(),
            $currency,
            $total,
            $sameCurrency ? $document->exchangeRate() : null,
            $sameCurrency ? $document->exchangeRateDate() : null,
            $recap,
            $document->originalDocumentId(),
            $document->supplyCode(),
        );
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * The rows of a read VAT recap, or null for anything else.
     *
     * @return list<array{rate: string, baseMinor: int, vatMinor: int}>|null
     */
    private static function recap(mixed $value): ?array
    {
        if (!\is_array($value) || [] === $value || !array_is_list($value)) {
            return null;
        }
        $rows = [];
        foreach ($value as $row) {
            if (!\is_array($row) || !\is_string($row['rate'] ?? null) || !\is_int($row['baseMinor'] ?? null) || !\is_int($row['vatMinor'] ?? null)) {
                return null;
            }
            $rows[] = ['rate' => $row['rate'], 'baseMinor' => $row['baseMinor'], 'vatMinor' => $row['vatMinor']];
        }

        return $rows;
    }

    /**
     * @param list<ReadField> $fields
     *
     * @return list<string>
     */
    private function keys(array $fields): array
    {
        return array_map(static fn (ReadField $field): string => $field->value, $fields);
    }
}
