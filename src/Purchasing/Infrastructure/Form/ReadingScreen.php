<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Purchasing\Infrastructure\Form;

use Foresi\Party\Domain\Party;
use Foresi\Purchasing\Application\Reading\ReadAgain;
use Foresi\Purchasing\Application\Reading\ReadValues;
use Foresi\Purchasing\Application\ReceivedDocumentWarning;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\Reading;
use Foresi\Purchasing\Contract\Reading\ReadValue;
use Foresi\Purchasing\Domain\DocumentReading;
use Foresi\Purchasing\Domain\ReadingState;
use Foresi\Purchasing\Domain\ReceivedDocument;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The reading of a received document's file as its page shows it: where the
 * reading stands and what read the values, a mark with its source at each
 * field that still holds the value read, the values left as suggestions that
 * the document does not hold, and what the reading names as a supplier nobody
 * in the address book matched, to create it.
 *
 * @phpstan-type Suggestion array{field: string, label: string, value: string}
 * @phpstan-type Screen array{state: string, live: bool, sources: string, warnings: list<string>, retry: bool, marks: array<string, string>, suggestions: list<Suggestion>, supplier: array<string, string>|null}
 */
final readonly class ReadingScreen
{
    /** The sources the readers name, each with its own words; any other is "the file". */
    private const array SOURCES = ['isdoc', 'payment_qr', 'factur_x', 'ai'];

    /** The id of the field of the form that shows each field a draft takes from a reading. */
    public const array INPUTS = [
        ReadField::Kind->value => 'kind',
        ReadField::SupplierName->value => 'supplier',
        ReadField::SupplierNumber->value => 'supplierNumber',
        ReadField::VariableSymbol->value => 'variableSymbol',
        ReadField::IssueDate->value => 'issueDate',
        ReadField::TaxPointDate->value => 'taxPointDate',
        ReadField::DueDate->value => 'dueDate',
        ReadField::Currency->value => 'currency',
        ReadField::Total->value => 'total',
        ReadField::VatRecap->value => 'vatRecap',
    ];

    public function __construct(
        private TranslatorInterface $translator,
        private PurchasingText $text,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return Screen|null null for a document with no reading, or in the trash
     */
    public function of(ReceivedDocument $document, ?DocumentReading $reading): ?array
    {
        if (!$reading instanceof DocumentReading || $document->isTrashed()) {
            return null;
        }
        $read = $reading->reading();
        $draft = !$document->isRecorded();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        // A reading asked for long ago that never finished is stuck: it shows as failed, to read again.
        $stuck = $reading->isRunning() && ReadAgain::mayStart($reading, $now);

        return [
            'state' => $stuck ? ReadingState::Failed->value : $reading->state()->value,
            'live' => $reading->isRunning() && !$stuck,
            'sources' => implode(', ', array_map(fn (string $source): string => $this->translator->trans('purchasing.reading.source.'.$source), $this->sources($read))),
            'warnings' => array_map(fn (string $code): string => $this->translator->trans('purchasing.warning.'.$code), $reading->warnings()),
            'retry' => $draft && null !== $document->originalAttachmentId() && ReadAgain::mayStart($reading, $now),
            'marks' => $this->marks($document, $read),
            'suggestions' => $draft ? $this->suggestions($document, $read, $reading->suggested()) : [],
            'supplier' => $draft ? $this->supplier($document, $read, $reading->warnings()) : null,
        ];
    }

    /**
     * The sources of the reading's values in reader order, each once, as their keys.
     *
     * @return list<string>
     */
    private function sources(Reading $read): array
    {
        $sources = array_values(array_unique(array_map(static fn (ReadValue $value): string => \in_array($value->source, self::SOURCES, true) ? $value->source : 'other', $read->values)));
        $order = array_flip(self::SOURCES);
        usort($sources, static fn (string $a, string $b): int => ($order[$a] ?? \count($order)) <=> ($order[$b] ?? \count($order)));

        return $sources;
    }

    /**
     * "From ISDOC" by the id of each field that holds the value read.
     *
     * @return array<string, string>
     */
    private function marks(ReceivedDocument $document, Reading $read): array
    {
        $marks = [];
        foreach (self::INPUTS as $key => $input) {
            $field = ReadField::from($key);
            $value = $read->get($field);
            if ($value instanceof ReadValue && ReadValues::holds($document, $read, $field)) {
                $marks[$input] = $this->translator->trans('purchasing.reading.from.'.(\in_array($value->source, self::SOURCES, true) ? $value->source : 'other'));
            }
        }

        return $marks;
    }

    /**
     * The values left as suggestions that the document does not hold, in the order of the form.
     *
     * @param list<string> $suggested keys of ReadField
     *
     * @return list<Suggestion>
     */
    private function suggestions(ReceivedDocument $document, Reading $read, array $suggested): array
    {
        $suggestions = [];
        foreach (array_keys(self::INPUTS) as $key) {
            $field = ReadField::from($key);
            if (!\in_array($key, $suggested, true) || ReadValues::holds($document, $read, $field)) {
                continue;
            }
            $value = $this->value($read, $field);
            if (null !== $value) {
                $suggestions[] = ['field' => $key, 'label' => $this->translator->trans('purchasing.field.'.self::INPUTS[$key]), 'value' => $value];
            }
        }

        return $suggestions;
    }

    /**
     * A read value as the page shows it, or null for one of the wrong shape.
     */
    private function value(Reading $read, ReadField $field): ?string
    {
        if (ReadField::SupplierName !== $field && (!ReadValues::shaped($read, $field) || ReadValues::blockedAmount($read, $field))) {
            return null;
        }
        $value = $read->get($field)?->value;
        $currency = Currency::tryFrom(\is_string($currency = $read->get(ReadField::Currency)?->value) ? $currency : '') ?? Currency::CZK;
        $date = fn (\DateTimeImmutable $day): string => $day->format($this->translator->trans('format.date'));

        return match ($field) {
            ReadField::Kind => $this->translator->trans('purchasing.kind.'.(\is_string($value) ? $value : '')),
            ReadField::IssueDate, ReadField::TaxPointDate, ReadField::DueDate => ($day = ReadValues::date($value)) instanceof \DateTimeImmutable ? $date($day) : null,
            ReadField::Total => \is_int($value) ? $this->text->amount(Money::ofMinor($value, $currency)) : null,
            ReadField::VatRecap => implode('; ', array_map(fn (array $row): string => $this->translator->trans('purchasing.reading.recap_row', [
                'rate' => $row['rate'],
                'base' => $this->text->amount(Money::ofMinor($row['baseMinor'], $currency)),
                'vat' => $this->text->amount(Money::ofMinor($row['vatMinor'], $currency)),
            ]), ReadValues::recap($value) ?? [])),
            default => \is_string($value) && '' !== trim($value) ? trim($value) : null,
        };
    }

    /**
     * What the contact dialog is opened with for a supplier nobody matched: the name and numbers read.
     *
     * @param list<string> $warnings
     *
     * @return array<string, string>|null
     */
    private function supplier(ReceivedDocument $document, Reading $read, array $warnings): ?array
    {
        if ($document->supplierParty() instanceof Party || !\in_array(ReceivedDocumentWarning::SUPPLIER_NOT_FOUND, $warnings, true)) {
            return null;
        }
        $text = static fn (ReadField $field): string => \is_string($value = $read->get($field)?->value) ? trim($value) : '';
        $country = strtoupper($text(ReadField::SupplierCountry));
        $prefill = array_filter([
            'displayName' => $text(ReadField::SupplierName),
            'registrationNumber' => $text(ReadField::SupplierRegistrationNumber),
            'vatId' => $text(ReadField::SupplierVatId),
            'countryCode' => 1 === preg_match('/^[A-Z]{2}$/D', $country) ? $country : '',
        ], static fn (string $value): bool => '' !== $value);

        return [] === $prefill ? null : $prefill;
    }
}
