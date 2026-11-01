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
    private const float QR_X = 162.0;
    /** The top margin, then the label above the code. */
    private const float QR_Y = 21.0;
    private const string QR_BAND_HEIGHT = '40mm';
    private const array LOGO_BOX = [60.0, 20.0];
    private const array STAMP_BOX = [55.0, 28.0];
    /** Shares of the page; the text column takes what the others leave. */
    private const array PAYER_COLUMNS = ['quantity' => 7, 'unit' => 5, 'price' => 11, 'discount' => 6, 'rate' => 6, 'base' => 11, 'vat' => 10, 'total' => 11];
    private const array PLAIN_COLUMNS = ['quantity' => 9, 'unit' => 6, 'price' => 14, 'discount' => 7, 'total' => 14];

    public function __construct(
        private Environment $twig,
        private PdfEngine $engine,
        private InvoicingCountries $countries,
        private FirmImages $images,
        private TenantContext $tenants,
        private LocaleSwitcher $locales,
        private TranslatorInterface $translator,
        private AmountText $amounts,
    ) {
    }

    public function render(IssuedDocument $document): RenderedPdf
    {
        $country = $this->countries->for($document->issuer->address->countryCode);
        $file = $country->electronicFile($document);
        $payload = $country->paymentCode($document);

        return $this->locales->runWithLocale($document->language->value, function () use ($document, $country, $file, $payload): RenderedPdf {
            $view = $this->view($document, $country, null !== $payload);
            $template = $this->twig->load(self::TEMPLATE);

            $bytes = $this->engine->document(
                $this->trans('invoice.pdf.document_title', ['number' => $document->number]),
                $document->language->value,
                $template->renderBlock('body', ['doc' => $view]),
                $file instanceof \App\Invoicing\Application\ElectronicFile ? new EmbeddedFile($file->name, $file->content, $file->mime) : null,
                null === $payload ? null : new QrFigure($payload, $this->trans('invoice.pdf.qr_alt', ['number' => $document->number]), self::QR_X, self::QR_Y, self::QR_SIZE_MM),
                $template->renderBlock('head', ['doc' => $view]),
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

        return [
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
            'qrBandHeight' => self::QR_BAND_HEIGHT,
            'hasDiscount' => $hasDiscount,
            'columns' => $this->columns($payer, $hasDiscount),
            'lines' => array_map($this->line(...), $document->lines),
            'rounding' => $document->rounding->isZero() ? null : [
                'quantity' => $this->amounts->quantity('1.0000'),
                'amount' => $this->amount($document->rounding),
                'zero' => $this->amount(Money::ofMinor(0, $document->currency)),
            ],
            'notes' => $this->notes($document, $country),
            'recap' => array_map($this->recapRow(...), $document->summaries),
            'net' => $this->amount($document->net),
            'vat' => $this->hasCharge($document) ? $this->amount($document->vat) : '',
            'gross' => $this->amount($document->gross),
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
     * @return array<string, string>
     */
    private function columns(bool $payer, bool $hasDiscount): array
    {
        $columns = $payer ? self::PAYER_COLUMNS : self::PLAIN_COLUMNS;
        if (!$hasDiscount) {
            unset($columns['discount']);
        }
        $widths = array_map(static fn (int $share): string => $share.'%', $columns);
        $widths['text'] = (100 - array_sum($columns)).'%';

        return $widths;
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
        return $this->amounts->trimmed($percent->decimal()).' %';
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
