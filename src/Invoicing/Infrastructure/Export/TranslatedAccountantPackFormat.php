<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Export;

use App\Invoicing\Application\AccountantDocument;
use App\Invoicing\Application\AccountantPackFormat;
use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Application\VatRecap;
use App\Invoicing\Application\VatRecapLine;
use App\Invoicing\Domain\DocumentKind;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Folder and file names from the translations, so a Czech pack has the Czech names
 * an accountant there expects, and the file of a document reads as the owner names
 * them by hand: day of issue, customer, amount and variable symbol. The list is an
 * XLSX: text cells are typed strings, so nothing in them runs as a formula, and
 * amounts are numbers to add up.
 */
final readonly class TranslatedAccountantPackFormat implements AccountantPackFormat
{
    private AsciiSlugger $slugger;

    public function __construct(private TranslatorInterface $translator)
    {
        $this->slugger = new AsciiSlugger();
    }

    public function packName(LegalEntity $firm, \DateTimeImmutable $month): string
    {
        return \sprintf('%s_%s.zip', $this->slug($firm->name()), $month->format('Y-m'));
    }

    public function folder(DocumentKind $kind, string $locale): string
    {
        return $this->translator->trans('invoice.export.folder.'.$kind->value, [], null, $locale);
    }

    public function baseName(IssuedDocument $document, string $locale): string
    {
        $symbol = '' === $document->variableSymbol ? $this->slug($document->number) : 'VS'.$this->slug($document->variableSymbol);

        return \sprintf(
            '%s_%s_%s%s_%s',
            $document->issueDate->format('Y-m-d'),
            $this->slug($document->customer->name),
            $this->amount($document->payable),
            $this->translator->trans('invoice.export.currency', ['code' => $document->currency->value], null, $locale),
            $symbol,
        );
    }

    public function listName(string $locale): string
    {
        return $this->translator->trans('invoice.export.list_file', [], null, $locale);
    }

    public function writeList(string $path, array $documents, array $recap, Currency $home, string $locale): void
    {
        $writer = new Writer();
        $writer->openToFile($path);
        try {
            $writer->getCurrentSheet()->setName($this->trans('invoice.export.sheet.documents', $locale));
            $writer->addRow($this->texts(array_map(fn (string $column): string => $this->trans('invoice.export.column.'.$column, $locale), ['number', 'kind', 'customer', 'registration', 'vat_id', 'issue_date', 'tax_point', 'due_date', 'currency', 'exchange_rate', 'base', 'vat', 'total', 'payable', 'state'])));
            foreach ($documents as $listed) {
                $writer->addRow($this->documentRow($listed, $locale));
            }

            $writer->addNewSheetAndMakeItCurrent()->setName($this->trans('invoice.export.sheet.recap', $locale));
            $writer->addRow($this->texts(array_map(fn (string $column): string => $this->translator->trans('invoice.export.recap.'.$column, ['currency' => $home->value], null, $locale), ['treatment', 'rate', 'base', 'vat', 'total'])));
            foreach ($recap as $line) {
                $writer->addRow(new Row([
                    $this->text($this->trans('invoice.vat.'.$line->treatment->value, $locale)),
                    null === $line->rate ? $this->text('') : new NumericCell($line->rate->basisPoints() / 100),
                    ...$this->amounts($line),
                ]));
            }
            $total = VatRecap::total($recap, $home);
            $writer->addRow(new Row([$this->text($this->trans('invoice.export.recap.sum', $locale)), $this->text(''), ...$this->amounts($total)]));
        } finally {
            $writer->close();
        }
    }

    /**
     * The payable without a fraction when it has none, else with a dot: 3219, 121.50.
     */
    private function amount(Money $money): string
    {
        $minor = $money->minor();
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return 0 === $minor % 100 ? $sign.intdiv($minor, 100) : \sprintf('%s%d.%02d', $sign, intdiv($minor, 100), $minor % 100);
    }

    private function number(Money $money): float
    {
        return $money->minor() / 100;
    }

    private function documentRow(AccountantDocument $listed, string $locale): Row
    {
        $document = $listed->document;

        return new Row([
            $this->text($document->number),
            $this->text($this->trans('series.kind.'.$document->kind->value, $locale)),
            $this->text($document->customer->name),
            $this->text($document->customer->registrationNumber ?? ''),
            $this->text($document->customer->vatId ?? ''),
            $this->text($document->issueDate->format('Y-m-d')),
            $this->text($document->taxPointDate?->format('Y-m-d') ?? ''),
            $this->text($document->dueDate->format('Y-m-d')),
            $this->text($document->currency->value),
            new NumericCell((float) $document->exchangeRate),
            new NumericCell($this->number($document->net)),
            new NumericCell($this->number($document->vat)),
            new NumericCell($this->number($document->gross)),
            new NumericCell($this->number($document->payable)),
            $this->text($this->trans('invoice.export_state.'.$listed->state->value, $locale)),
        ]);
    }

    /**
     * @return list<NumericCell>
     */
    private function amounts(VatRecapLine $line): array
    {
        return [new NumericCell($this->number($line->base)), new NumericCell($this->number($line->vat)), new NumericCell($this->number($line->gross))];
    }

    /**
     * @param list<string> $texts
     */
    private function texts(array $texts): Row
    {
        return new Row(array_map($this->text(...), $texts));
    }

    /**
     * Always a string cell, also for a text that starts with "=".
     */
    private function text(string $text): StringCell
    {
        return new StringCell($text);
    }

    private function trans(string $key, string $locale): string
    {
        return $this->translator->trans($key, [], null, $locale);
    }

    private function slug(string $text): string
    {
        return $this->slugger->slug($text)->lower()->toString();
    }
}
