<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Application\Reading;

use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\Reading;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Purchasing\Domain\ReceivedDocumentRefused;
use Foresi\Purchasing\Domain\VatRate;
use Foresi\Purchasing\Domain\VatRecapRow;
use Foresi\Shared\Domain\Currency;

/**
 * The values of a reading as a draft holds them: the shapes the readers write
 * (ReadValue), and whether a document still holds what was read, so its page
 * marks the value with its source while nobody has changed it. A credit note
 * holds its amounts negative while the formats write them positive, so an
 * amount compares by its size.
 */
final class ReadValues
{
    /** The fields a draft takes from a reading; the others stay in the reading only. */
    public const array FIELDS = [
        ReadField::Kind, ReadField::SupplierNumber, ReadField::VariableSymbol, ReadField::IssueDate,
        ReadField::TaxPointDate, ReadField::DueDate, ReadField::Currency, ReadField::Total, ReadField::VatRecap,
    ];

    /**
     * Whether the value of this field is one a draft can take.
     */
    public static function shaped(Reading $reading, ReadField $field): bool
    {
        $value = $reading->get($field)?->value;

        return match ($field) {
            ReadField::Kind => \is_string($value) && null !== ReceivedDocumentKind::tryFrom($value),
            ReadField::IssueDate, ReadField::TaxPointDate, ReadField::DueDate => self::date($value) instanceof \DateTimeImmutable,
            ReadField::Currency => \is_string($value) && null !== Currency::tryFrom($value),
            ReadField::Total => \is_int($value),
            ReadField::VatRecap => null !== self::recap($value),
            default => \is_string($value),
        };
    }

    /**
     * Whether the reading names a currency Foresi does not support. Its amounts
     * must not be taken as amounts of another currency.
     */
    public static function foreignCurrency(Reading $reading): bool
    {
        $value = $reading->get(ReadField::Currency)?->value;

        return \is_string($value) && null === Currency::tryFrom($value);
    }

    /**
     * Whether the field is an amount a draft cannot take for this reading.
     */
    public static function blockedAmount(Reading $reading, ReadField $field): bool
    {
        return \in_array($field, [ReadField::Total, ReadField::VatRecap], true) && self::foreignCurrency($reading);
    }

    /**
     * Whether the document holds the value read for this field. The supplier is
     * held while the document names it with the name or a number read.
     */
    public static function holds(ReceivedDocument $document, Reading $reading, ReadField $field): bool
    {
        $value = $reading->get($field)?->value;
        if (null === $value) {
            return false;
        }

        return match ($field) {
            ReadField::Kind => $document->kind()->value === $value,
            ReadField::SupplierNumber => $document->supplierNumber() === $value,
            ReadField::VariableSymbol => $document->variableSymbol() === $value,
            ReadField::IssueDate => $document->issueDate()?->format('Y-m-d') === $value,
            ReadField::TaxPointDate => $document->taxPointDate()?->format('Y-m-d') === $value,
            ReadField::DueDate => $document->dueDate()?->format('Y-m-d') === $value,
            ReadField::Currency => $document->currency()->value === $value,
            ReadField::Total => \is_int($value) && $document->total() instanceof \Foresi\Shared\Domain\Money && abs($document->total()->minor()) === abs($value),
            ReadField::VatRecap => self::sameRecap($document, $value),
            ReadField::SupplierName => self::sameSupplier($document, $reading),
            default => false,
        };
    }

    /**
     * A read date, or null for anything but a day as `Y-m-d`.
     */
    public static function date(mixed $value): ?\DateTimeImmutable
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
    public static function recap(mixed $value): ?array
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

    private static function sameRecap(ReceivedDocument $document, mixed $value): bool
    {
        $rows = self::recap($value);
        $held = $document->vatRecap()->rows();
        if (null === $rows || \count($rows) !== \count($held)) {
            return false;
        }
        try {
            $read = array_map(static fn (array $row): array => [VatRate::of($row['rate'])->percent(), abs($row['baseMinor']), abs($row['vatMinor'])], $rows);
        } catch (ReceivedDocumentRefused) {
            return false;
        }
        $kept = array_map(static fn (VatRecapRow $row): array => [$row->rate->percent(), abs($row->base->minor()), abs($row->vat->minor())], $held);

        return $read === $kept;
    }

    private static function sameSupplier(ReceivedDocument $document, Reading $reading): bool
    {
        $snapshot = $document->supplier();
        $plain = static fn (mixed $text): string => \is_string($text) ? mb_strtolower((string) preg_replace('/\s+/u', '', $text)) : '';
        $name = $plain($reading->get(ReadField::SupplierName)?->value);
        $number = $plain($reading->get(ReadField::SupplierRegistrationNumber)?->value);
        $vatId = $plain($reading->get(ReadField::SupplierVatId)?->value);

        return ('' !== $name && $plain($snapshot->name) === $name)
            || ('' !== $number && $plain($snapshot->registrationNumber) === $number)
            || ('' !== $vatId && $plain($snapshot->vatId) === $vatId);
    }
}
