<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Infrastructure\Form;

use App\Purchasing\Application\Query\ReceivedDocumentRow;
use App\Purchasing\Application\ReceivedDocumentWarning;
use App\Purchasing\Application\ReceivedDocumentWarnings;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocument;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Purchasing\Domain\ReceivedDocumentRefused;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the screens of received documents share: the choices of the form, its
 * errors as the summary lists them, the warnings and refusals in words, and a
 * document's state as a badge.
 */
final readonly class ReceivedDocumentScreen
{
    public function __construct(private TranslatorInterface $translator, private PurchasingText $text)
    {
    }

    /**
     * @return array<string, string>
     */
    public function kinds(): array
    {
        $kinds = [];
        foreach (ReceivedDocumentKind::cases() as $kind) {
            $kinds[$kind->value] = $this->translator->trans('purchasing.kind.'.$kind->value);
        }

        return $kinds;
    }

    /**
     * @return array<string, string>
     */
    public function treatments(): array
    {
        $treatments = [];
        foreach (PurchaseTaxTreatment::cases() as $treatment) {
            $treatments[$treatment->value] = $this->translator->trans('purchasing.treatment.'.$treatment->value);
        }

        return $treatments;
    }

    /**
     * @return array<string, string>
     */
    public function currencies(): array
    {
        $currencies = [];
        foreach (Currency::cases() as $currency) {
            $currencies[$currency->value] = $currency->value;
        }

        return $currencies;
    }

    /**
     * The documents a credit note may correct, as the options of a select.
     *
     * @param list<ReceivedDocumentRow> $rows
     *
     * @return array<string, string>
     */
    public function correctable(array $rows): array
    {
        $options = ['' => $this->translator->trans('purchasing.field.originalDocument_none')];
        foreach ($rows as $row) {
            $options[$row->id] = $this->label($row);
        }

        return $options;
    }

    /**
     * "R20260001 · Supplier Example s.r.o. · FV-2026-123".
     */
    public function label(ReceivedDocumentRow $row): string
    {
        return implode(' · ', array_filter([$row->evidenceNumber ?? $this->translator->trans('purchasing.state.draft'), $row->supplierName, $row->supplierNumber], static fn (string $part): bool => '' !== $part));
    }

    /**
     * The title of a document: its evidence number, or the draft and when it came.
     */
    public function title(ReceivedDocument $document): string
    {
        return $document->evidenceNumber() ?? $this->translator->trans('purchasing.detail.draft_title', ['date' => $document->createdAt()->format($this->translator->trans('format.date'))]);
    }

    /**
     * @return array{label: string, tone: string}
     */
    public function badge(bool $trashed, bool $recorded): array
    {
        return match (true) {
            $trashed => ['label' => $this->translator->trans('purchasing.state.trashed'), 'tone' => 'gray'],
            $recorded => ['label' => $this->translator->trans('purchasing.state.recorded'), 'tone' => 'green'],
            default => ['label' => $this->translator->trans('purchasing.state.draft'), 'tone' => 'amber'],
        };
    }

    /**
     * The message of a refusal, by its stable reason.
     */
    public function refusal(ReceivedDocumentRefused $refused): string
    {
        return $this->translator->trans('purchasing.refused.'.$refused->reason, ['max' => ReceivedDocument::MAX_REASON_LENGTH]);
    }

    /**
     * The warnings of a saved document in words.
     *
     * @return list<string>
     */
    public function warnings(ReceivedDocumentWarnings $warnings): array
    {
        return array_map(fn (ReceivedDocumentWarning $warning): string => $this->translator->trans('purchasing.warning.'.$warning->code, [
            'count' => \count($warning->documentIds),
            'date' => $warning->date?->format($this->translator->trans('format.date')) ?? '',
        ]), $warnings->all());
    }

    /**
     * The fields to fix, each with its label, the input it links to and its message, in the order of the form.
     *
     * @param array<string, string> $errors message by input id; "form" for what no field fixes
     *
     * @return list<array{field: ?string, href: ?string, message: string}>
     */
    public function summary(array $errors): array
    {
        $summary = [];
        foreach ($errors as $id => $message) {
            if (RefusalFields::FORM === $id) {
                $summary[] = ['field' => null, 'href' => null, 'message' => $message];
                continue;
            }
            $summary[] = ['field' => $this->fieldLabel($id), 'href' => '#'.$id, 'message' => $message];
        }

        return $summary;
    }

    /**
     * "Supplier", or "VAT recap, row 2: Base" for a cell of the recap.
     */
    public function fieldLabel(string $id): string
    {
        if (1 === preg_match('/^recap-(\d+)-(\w+)$/D', $id, $match)) {
            return $this->translator->trans('purchasing.recap.row', ['number' => (int) $match[1] + 1]).': '.$this->translator->trans('purchasing.recap.'.$match[2]);
        }

        return $this->translator->trans('purchasing.field.'.$id);
    }

    /**
     * The amounts of a stored recap in words, row by row, with its totals in CZK.
     *
     * @return array{rows: list<array{rate: string, base: string, vat: string, baseCzk: ?string, vatCzk: ?string}>, base: ?string, vat: ?string, total: ?string}
     */
    public function figures(ReceivedDocument $document): array
    {
        $rows = [];
        foreach ($document->vatRecap()->rows() as $row) {
            $rows[] = [
                'rate' => $this->text->decimal($row->rate->percent()).' %',
                'base' => $this->text->amount($row->base),
                'vat' => $this->text->amount($row->vat),
                'baseCzk' => null === $row->baseCzk ? null : $this->text->amount($row->baseCzk),
                'vatCzk' => null === $row->vatCzk ? null : $this->text->amount($row->vatCzk),
            ];
        }
        $base = $document->baseCzk();
        $vat = $document->vatCzk();

        return [
            'rows' => $rows,
            'base' => $base instanceof Money ? $this->text->amount($base) : null,
            'vat' => $vat instanceof Money ? $this->text->amount($vat) : null,
            'total' => !$base instanceof Money || !$vat instanceof Money ? null : $this->text->amount($base->plus($vat)),
        ];
    }

    /**
     * Months as the options of a select, newest first, named in the language of the user.
     *
     * @param list<string> $months as YYYY-MM
     *
     * @return array<string, string>
     */
    public function months(array $months): array
    {
        $formatter = new \IntlDateFormatter($this->translator->getLocale(), \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'LLLL y');
        $choices = [];
        foreach (array_unique($months) as $month) {
            $choices[$month] = (string) $formatter->format(new \DateTimeImmutable($month.'-01', new \DateTimeZone('UTC')));
        }
        krsort($choices);

        return $choices;
    }
}
