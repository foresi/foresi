<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Pdf;

use App\Invoicing\Application\InvoicePdf;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\InvoicingCountry;
use App\Invoicing\Application\IssuedDocument;
use App\Invoicing\Application\RenderedPdf;
use App\Invoicing\Application\UndrawableDocument;
use App\Invoicing\Domain\DocumentParty;
use App\Invoicing\Domain\InvoiceLine;
use App\Invoicing\Domain\NumberKey;
use App\Invoicing\Domain\Percent;
use App\Invoicing\Domain\PriceType;
use App\Invoicing\Domain\RateSummary;
use App\Invoicing\Domain\TaxTreatment;
use App\Invoicing\Domain\VatRegime;
use App\Invoicing\Infrastructure\Form\AmountText;
use App\Party\Application\FirmImages;
use App\Shared\Domain\Countries;
use App\Shared\Domain\Money;
use App\Tenancy\Application\TenantContext;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The invoice PDF. The template is autoescaped, so text of a document can never
 * become markup, and everything it prints comes from the document given.
 *
 * The unit price the table prints is the one before the discount, and the base the
 * one after it. A stored unit net is already after the discount: printed beside the
 * discount column it would read as discounted twice. So a discounted line shows the
 * stored price, without VAT by the rule the line was priced with, and a line without
 * a discount shows the stored unit net.
 */
final readonly class TcpdfInvoicePdf implements InvoicePdf
{
    public const float QR_SIZE_MM = 32.0;
    private const string TEMPLATE = 'invoicing/pdf/invoice.html.twig';
    /** Inside the white plate of the panel, on a page whose body starts at the top margin. */
    private const float QR_X = 125.6;
    private const float QR_Y = 78.0;
    private const array LOGO_BOX = [52.0, 16.0];
    private const array STAMP_BOX = [48.0, 24.0];
    /** The least a column takes, in millimetres; the text column has what the others leave. */
    private const array PAYER_COLUMNS = ['quantity' => 12.6, 'unit' => 9.0, 'price' => 19.8, 'discount' => 10.8, 'rate' => 10.8, 'base' => 19.8, 'vat' => 18.0, 'total' => 19.8];
    private const array PLAIN_COLUMNS = ['quantity' => 16.2, 'unit' => 10.8, 'price' => 25.2, 'discount' => 12.6, 'total' => 25.2];
    private const array RECAP_COLUMNS = ['base' => 21.9, 'vat' => 21.9, 'total' => 23.0];
    /** The items table shrinks its type before a value would break. */
    private const array TABLE_SIZES = [8.0, 7.5, 7.0, 6.5, 6.0];
    private const float SMALLEST_TABLE_SIZE = 6.0;
    private const float LAST_RESORT_SHARE = 0.6;
    private const float TABLE_WIDTH_MM = 180.0;
    private const float RECAP_WIDTH_MM = 115.2;
    private const float CELL_PADDING_MM = 3.0;
    private const float LEAST_TEXT_MM = 40.0;
    private const float LEAST_LABEL_MM = 36.0;
    /** The document and order numbers shrink to one line. */
    private const array NUMBER_SIZES = [20.0, 17.0, 14.0, 12.0, 10.0, 8.5, 7.5, 6.5];
    private const array ORDER_SIZES = [8.5, 7.5, 6.5, 5.5];
    private const float NUMBER_WIDTH_QR_MM = 96.0;
    private const float NUMBER_WIDTH_MM = 88.0;
    private const float ORDER_WIDTH_MM = 58.0;

    public function __construct(
        private Environment $twig,
        private PdfEngine $engine,
        private InvoicingCountries $countries,
        private FirmImages $images,
        private TenantContext $tenants,
        private LocaleSwitcher $locales,
        private TranslatorInterface $translator,
        private AmountText $amounts,
        private PdfFont $font,
    ) {
    }

    public function render(IssuedDocument $document): RenderedPdf
    {
        $country = $this->countries->for($document->issuer->address->countryCode);
        // A document in the trash is no longer one to pay or to import.
        $file = $document->trashed ? null : $country->electronicFile($document);
        $payload = $document->trashed ? null : $country->paymentCode($document);

        return $this->locales->runWithLocale($document->language->value, function () use ($document, $country, $file, $payload): RenderedPdf {
            $view = $this->view($document, $country, null !== $payload);
            $template = $this->twig->load(self::TEMPLATE);

            $bytes = $this->engine->document(
                $this->trans('invoice.pdf.document_title', ['number' => $document->number]),
                $document->language->value,
                $template->renderBlock('body', ['doc' => $view]),
                $file instanceof \App\Invoicing\Application\ElectronicFile ? new EmbeddedFile($file->name, $file->content, $file->mime) : null,
                null === $payload ? null : new QrFigure($payload, $this->trans('invoice.pdf.qr_alt', ['number' => $document->number]), self::QR_X, self::QR_Y, self::QR_SIZE_MM),
                trim($template->renderBlock('head', ['doc' => $view])),
                fn (int $page, int $pages): string => $this->trans('invoice.pdf.footer', ['title' => $this->trans('invoice.pdf.document_title', ['number' => $document->number]), 'page' => (string) $page, 'pages' => (string) $pages]),
            );

            return new RenderedPdf($bytes, NumberKey::of($document->number).($file instanceof \App\Invoicing\Application\ElectronicFile ? '-isdoc.pdf' : '.pdf'));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function view(IssuedDocument $document, InvoicingCountry $country, bool $hasQr): array
    {
        $payer = VatRegime::Payer === $document->regime;
        $hasDiscount = array_any($document->lines, static fn (InvoiceLine $line): bool => 0 < $line->discount()->basisPoints());

        $lines = array_map($this->line(...), $document->lines);
        $rounding = $document->rounding->isZero() ? null : [
            'quantity' => $this->amounts->quantity('1.0000'),
            'amount' => $this->amount($document->rounding),
            'zero' => $this->amount(Money::ofMinor(0, $document->currency)),
        ];
        $recap = array_map($this->recapRow(...), $document->summaries);
        $totals = ['base' => $this->amount($document->net), 'vat' => $this->hasCharge($document) ? $this->amount($document->vat) : '', 'total' => $this->amount($document->gross)];
        [$columns, $size] = $this->columns($payer, $hasDiscount, $lines, $rounding);
        [$recapColumns, $recapSize] = $payer ? $this->fitted(self::RECAP_COLUMNS, [...$recap, $totals], 'label', self::RECAP_WIDTH_MM, self::LEAST_LABEL_MM, $size) : [[], $size];

        return [
            'trashed' => $document->trashed,
            'columns' => $columns,
            'tableSize' => $size,
            'recapSize' => $recapSize,
            'numberSize' => $this->stepped($document->number, $hasQr ? self::NUMBER_WIDTH_QR_MM : self::NUMBER_WIDTH_MM, self::NUMBER_SIZES),
            'orderSize' => $this->stepped($document->orderNumber, self::ORDER_WIDTH_MM, self::ORDER_SIZES),
            'recapColumns' => $recapColumns,
            'payer' => $payer,
            'title' => $this->trans($payer ? 'invoice.pdf.title_tax' : 'invoice.pdf.title'),
            'number' => $document->number,
            'issuer' => $this->party($document->issuer, $payer),
            'customer' => $this->party($document->customer, $payer),
            'issueDate' => $this->date($document->issueDate),
            'taxPointDate' => $this->date($document->taxPointDate),
            'taxPointLabel' => $this->trans($payer ? 'invoice.pdf.tax_point' : 'invoice.pdf.supply_date'),
            'dueDate' => $this->date($document->dueDate),
            'account' => $country->domesticAccount($document->bankAccount ?? ''),
            'iban' => $document->bankAccount,
            'paymentMethod' => $this->trans('invoice.payment.'.$document->paymentMethod->value),
            'variableSymbol' => $document->variableSymbol,
            'constantSymbol' => $document->constantSymbol,
            'orderNumber' => $document->orderNumber,
            'description' => $document->description,
            'textBefore' => $document->textBefore,
            'textAfter' => $document->textAfter,
            'hasQr' => $hasQr,
            'hasDiscount' => $hasDiscount,
            'lines' => $lines,
            'rounding' => $rounding,
            'notes' => $this->notes($document, $country),
            'recap' => $recap,
            'net' => $totals['base'],
            'vat' => $totals['vat'],
            'gross' => $totals['total'],
            'payable' => $this->money($document->payable),
            'logo' => $this->image($document->issuer->logo, self::LOGO_BOX, $document->issuer->name),
            'stamp' => $this->image($document->issuer->stamp, self::STAMP_BOX, $this->trans('invoice.pdf.stamp_alt', ['name' => $document->issuer->name])),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function party(DocumentParty $party, bool $payer): array
    {
        return [
            'name' => $party->name,
            'street' => $party->address->street,
            'postalCode' => $party->address->postalCode,
            'city' => $party->address->city,
            'country' => Countries::name($party->address->countryCode, $this->locales->getLocale()),
            'registrationNumber' => $party->registrationNumber,
            // A non-payer document names no VAT number, the customer's included.
            'vatId' => $payer ? $party->vatId : null,
            'registerEntry' => $party->registerEntry,
            'email' => $party->email,
            'phone' => $party->phone,
            'web' => $party->web,
        ];
    }

    /**
     * The widths of the items table and the size of its type, from what the cells hold.
     *
     * @param list<array<string, string>> $lines
     * @param array<string, string>|null  $rounding
     *
     * @return array{array<string, string>, float}
     */
    private function columns(bool $payer, bool $hasDiscount, array $lines, ?array $rounding): array
    {
        $least = $payer ? self::PAYER_COLUMNS : self::PLAIN_COLUMNS;
        if (!$hasDiscount) {
            unset($least['discount']);
        }
        $rows = null === $rounding ? $lines : [...$lines, ['quantity' => $rounding['quantity'], 'price' => $rounding['amount'], 'base' => $rounding['amount'], 'vat' => $rounding['zero'], 'total' => $rounding['amount']]];

        return $this->fitted($least, $rows, 'text', self::TABLE_WIDTH_MM, self::LEAST_TEXT_MM);
    }

    /**
     * Gives every column room for its longest value, and the free column the rest,
     * at the largest type that leaves the free column usable. A value then has no
     * reason to break: only the free column wraps, and a unit at its spaces. Sizes
     * start at the given one.
     *
     * @param array<string, float>        $least
     * @param list<array<string, string>> $rows
     *
     * @return array{array<string, string>, float}
     *
     * @throws UndrawableDocument when no size leaves the free column usable
     */
    private function fitted(array $least, array $rows, string $free, float $width, float $leastFree, ?float $size = null): array
    {
        foreach (self::TABLE_SIZES as $candidate) {
            if (null !== $size && $candidate > $size) {
                continue;
            }
            $widths = $least;
            foreach (array_keys($least) as $column) {
                foreach ($rows as $row) {
                    $widths[$column] = max($widths[$column], $this->font->longestWord($row[$column] ?? '', $candidate) + self::CELL_PADDING_MM);
                }
            }
            // The smallest type may narrow the free column further: it is the last step before refusing.
            if ($width - array_sum($widths) >= $leastFree * (self::SMALLEST_TABLE_SIZE === $candidate ? self::LAST_RESORT_SHARE : 1.0)) {
                $columns = array_map(static fn (float $mm): string => \sprintf('%.2F%%', 100 * $mm / $width), $widths);
                $columns[$free] = \sprintf('%.2F%%', 100 * ($width - array_sum($widths)) / $width);

                return [$columns, $candidate];
            }
        }

        throw new UndrawableDocument('The values of the document do not fit its table.');
    }

    /**
     * The largest size that keeps a text on one line, or the smallest there is.
     *
     * @param non-empty-list<float> $sizes from the largest
     */
    private function stepped(string $text, float $width, array $sizes): float
    {
        foreach ($sizes as $size) {
            if ($this->font->width($text, $size) <= $width) {
                return $size;
            }
        }

        return $sizes[array_key_last($sizes)];
    }

    /**
     * @return array<string, string>
     */
    private function line(InvoiceLine $line): array
    {
        $rate = $line->treatment()->hasRate() ? $line->rate() : null;
        $discounted = 0 < $line->discount()->basisPoints();

        return [
            'text' => $line->text(),
            'quantity' => $this->amounts->quantity($line->quantity()->toDecimal()),
            'unit' => $line->unit(),
            'price' => $this->amount($this->unitPrice($line)),
            'discount' => $discounted ? $this->percent($line->discount()) : '',
            'rate' => $rate instanceof Percent ? $this->amounts->trimmed($rate->decimal()) : '',
            'base' => $this->amount($line->net()),
            'vat' => $rate instanceof Percent ? $this->amount($line->vat()) : '',
            'total' => $this->amount($line->gross()),
        ];
    }

    /**
     * The price without VAT and before the discount; see the class comment.
     */
    private function unitPrice(InvoiceLine $line): Money
    {
        if (0 === $line->discount()->basisPoints()) {
            return $line->unitNet();
        }
        $rate = $line->rate();

        return PriceType::WithVat === $line->priceType() && $rate instanceof Percent
            ? $line->price()->dividedBy($rate->inclusiveFactor())
            : $line->price();
    }

    /**
     * Each statutory note once, with every code the lines name.
     *
     * @return list<string>
     */
    private function notes(IssuedDocument $document, InvoicingCountry $country): array
    {
        $notes = [];
        foreach ([TaxTreatment::ReverseCharge, TaxTreatment::Exempt] as $treatment) {
            $lines = array_filter($document->lines, static fn (InvoiceLine $line): bool => $treatment === $line->treatment());
            $key = [] === $lines ? null : $country->noteKey($treatment);
            if (null === $key) {
                continue;
            }
            $codes = array_unique(array_filter(array_map(static fn (InvoiceLine $line): string => (string) $line->supplyCode(), $lines), static fn (string $code): bool => '' !== $code));
            $notes[] = $this->trans($key, ['code' => [] === $codes ? 'none' : implode(', ', $codes)]);
        }

        return $notes;
    }

    /**
     * A row is named by its rate, or by the treatment where there is no rate to name.
     *
     * @return array<string, string>
     */
    private function recapRow(RateSummary $summary): array
    {
        $charged = $summary->rate instanceof Percent;

        return [
            'label' => $charged
                ? $this->trans('invoice.rate', ['rate' => $this->amounts->trimmed($summary->rate->decimal())])
                : $this->trans('invoice.pdf.recap.'.$summary->treatment->value),
            'base' => $this->amount($summary->base),
            'vat' => $charged ? $this->amount($summary->vat) : '',
            'total' => $this->amount($summary->gross),
        ];
    }

    private function hasCharge(IssuedDocument $document): bool
    {
        return array_any($document->summaries, static fn (RateSummary $summary): bool => $summary->rate instanceof Percent);
    }

    /**
     * A file gone from the store costs the picture, never the document.
     *
     * @param array{float, float} $box the most it may take, in millimetres
     *
     * @return array{path: string, alt: string, width: string, height: string}|null
     */
    private function image(?string $name, array $box, string $alt): ?array
    {
        if (null === $name) {
            return null;
        }
        try {
            $path = $this->images->path($this->tenants->current(), $name);
        } catch (\RuntimeException|\InvalidArgumentException) {
            return null;
        }
        $size = getimagesize($path);
        if (false === $size || 0 === $size[0] || 0 === $size[1]) {
            return null;
        }
        $scale = min($box[0] / $size[0], $box[1] / $size[1]);

        return [
            'path' => $path,
            'alt' => $alt,
            'width' => \sprintf('%.2Fmm', $size[0] * $scale),
            'height' => \sprintf('%.2Fmm', $size[1] * $scale),
        ];
    }

    private function amount(Money $money): string
    {
        return $this->amounts->grouped($money);
    }

    private function money(Money $money): string
    {
        return $this->amounts->money($money);
    }

    private function percent(Percent $percent): string
    {
        return $this->amounts->trimmed($percent->decimal())."\u{a0}%";
    }

    private function date(\DateTimeImmutable $date): string
    {
        return $date->format($this->trans('invoice.pdf.date_format'));
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters);
    }
}
