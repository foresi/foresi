<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Documents\Infrastructure;

use Foresi\Documents\Application\DocumentVariables;
use Foresi\Documents\Application\TemplateRenderer;
use Foresi\Documents\Contract\DocumentImageSource;
use Foresi\Documents\Contract\DocumentVariableSource;
use Foresi\Documents\Contract\VariableDefinition;
use Foresi\Documents\Domain\PageSettings;
use Foresi\Documents\Infrastructure\Pdf\TcpdfDocumentRenderer;
use Foresi\Shared\Infrastructure\Pdf\PdfEngine;
use Foresi\Tests\Support\VeraPdf;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Markup;

/**
 * The sample contract of the spike (ADR 0042) as a PDF/A-3a document.
 */
final class PdfRendererTest extends TestCase
{
    /** @var \ArrayObject<int, string> the footers drawn, in page order */
    private \ArrayObject $footers;

    private const array VALUES = [
        'firm' => ['name' => 'Příkladná firma s.r.o.', 'address_lines' => ['Žižkova 1234/5', '602 00 Brno'], 'registration_number' => '12345678', 'vat_id' => 'CZ12345678'],
        'client' => ['name' => 'Žluťoučký kůň a.s.', 'address_lines' => ['Náměstí Míru 7', '120 00 Praha 2'], 'registration_number' => '87654321', 'persons' => [['name' => 'Jana Nováková', 'email' => 'jana@example.com']]],
    ];

    #[Test]
    public function theSampleContractRendersTwoPagesOfPdfAWithFooters(): void
    {
        $html = new TemplateRenderer()->render((string) file_get_contents(__DIR__.'/../Fixtures/contract.html.twig'), self::VALUES);

        $pdf = $this->renderer()->render($html, new PageSettings(), 'Non-disclosure agreement', 'en');

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(2, preg_match_all('~/Type\s*/Page[^s]~', $pdf));
        foreach (['InterTabular-Regular', 'InterTabular-SemiBold', 'InterDisplay-SemiBold'] as $font) {
            self::assertMatchesRegularExpression('~/BaseFont /[A-Z]{6}\+'.$font.'~', $pdf);
        }
        self::assertStringContainsString('/FontFile2', $pdf);
        self::assertSame(['Page 1 of 2', 'Page 2 of 2'], $this->footers->getArrayCopy());
        VeraPdf::assertConforms($pdf, '3a');
    }

    #[Test]
    public function aTemplateWithGelasioAndTheLogoIsPdfA(): void
    {
        $directory = sys_get_temp_dir().'/foresi-logo-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $logo = $directory.'/logo.png';
        $image = imagecreatetruecolor(200, 80);
        \assert(false !== $image);
        imagepng($image, $logo);
        $variables = new DocumentVariables([$this->firm($logo)]);
        $body = '<style>h1, .name { font-family: gelasio; } .brand img { width: 20mm; height: 8mm; }</style>'
            .'<div class="brand">{% if firm.logo %}{{ firm.logo }}{% endif %} <span class="name">{{ firm.name }}</span></div>'
            .'<h1>Dohoda o mlčenlivosti – <b>zahájení spolupráce</b></h1><p>Příliš žluťoučký kůň úpěl ďábelské ódy.</p>';

        try {
            $html = new TemplateRenderer()->render($body, $variables->printable($variables->values([])));
            $pdf = new TcpdfDocumentRenderer(new PdfEngine(logoDirectories: [$directory]), $this->translator())->render($html, new PageSettings(), 'Non-disclosure agreement', 'cs');
        } finally {
            unlink($logo);
            rmdir($directory);
        }

        self::assertStringContainsString('<img src="'.$logo.'" alt="Firm logo" width="40.00mm" height="16.00mm">', $html);
        foreach (['Gelasio-Regular', 'Gelasio-Bold'] as $font) {
            self::assertMatchesRegularExpression('~/BaseFont /[A-Z]{6}\+'.$font.'~', $pdf);
        }
        self::assertSame(1, preg_match_all('~/Subtype\s*/Image~', $pdf));
        VeraPdf::assertConforms($pdf, '3a');
    }

    #[Test]
    public function gelasioIsEmbeddedOnlyWhenTheMarkupAsksForIt(): void
    {
        $pdf = $this->renderer()->render('<p>Plain Inter.</p>', new PageSettings(), 'T', 'en');

        self::assertStringNotContainsString('Gelasio', $pdf);
    }

    #[Test]
    public function aLogoWithoutAFilePrintsNothingInThePdfAndAPlaceholderInThePreview(): void
    {
        $variables = new DocumentVariables([$this->firm(null)]);
        $values = ['firm' => ['name' => 'Example Firm s.r.o.', 'logo' => 'gone.png']];

        self::assertSame('', $variables->printable($values)['firm']['logo']);
        $placeholder = $variables->printable($values, preview: true)['firm']['logo'];
        self::assertInstanceOf(Markup::class, $placeholder);
        self::assertStringContainsString('Firm logo', (string) $placeholder);
        self::assertSame('', $variables->printable(['firm' => ['name' => 'Example Firm s.r.o.', 'logo' => '']], preview: true)['firm']['logo']);
        // Only an image variable prints markup: a name is still escaped.
        $printed = new TemplateRenderer()->render('{{ firm.name }}{{ firm.logo }}', $variables->printable(['firm' => ['name' => '<b>x</b>', 'logo' => '']]));
        self::assertSame('&lt;b&gt;x&lt;/b&gt;', $printed);
    }

    #[Test]
    public function thePreviewCarriesTheLogoInline(): void
    {
        $logo = (string) tempnam(sys_get_temp_dir(), 'foresi-logo');
        $image = imagecreatetruecolor(100, 100);
        \assert(false !== $image);
        imagepng($image, $logo);
        $variables = new DocumentVariables([$this->firm($logo)]);

        try {
            $printed = $variables->printable($variables->values([]), preview: true)['firm']['logo'];
        } finally {
            unlink($logo);
        }
        self::assertInstanceOf(Markup::class, $printed);
        $printed = (string) $printed;

        self::assertStringStartsWith('<img src="data:image/png;base64,iVBORw0KGgo', $printed);
        self::assertStringEndsWith('alt="Firm logo" width="60" height="60">', $printed);
    }

    #[Test]
    public function thePageSettingsSetThePaper(): void
    {
        $pdf = $this->renderer()->render('<p>x</p>', new PageSettings(landscape: true), 'T', 'en');

        self::assertSame(1, preg_match('~/MediaBox \[[\d.]+ [\d.]+ ([\d.]+) ([\d.]+)\]~', $pdf, $box));
        self::assertSame([841.9, 595.3], [round((float) $box[1], 1), round((float) $box[2], 1)]);
    }

    /**
     * The firm with a logo whose file is $logo, or whose file is gone.
     */
    private function firm(?string $logo): DocumentVariableSource
    {
        return new readonly class($logo) implements DocumentVariableSource, DocumentImageSource {
            public function __construct(private ?string $logo)
            {
            }

            public function prefix(): string
            {
                return 'firm';
            }

            public function definitions(): array
            {
                return [new VariableDefinition('name', 'Firm name', 'Example Firm s.r.o.'), new VariableDefinition('logo', 'Firm logo', 'logo.png', image: true)];
            }

            public function values(array $links): array
            {
                return ['name' => 'Example Firm s.r.o.', 'logo' => 'logo.png'];
            }

            public function imageFile(string $variable, string $value): ?string
            {
                return 'logo' === $variable ? $this->logo : null;
            }
        };
    }

    private function renderer(): TcpdfDocumentRenderer
    {
        return new TcpdfDocumentRenderer(new PdfEngine(), $this->translator());
    }

    private function translator(): TranslatorInterface
    {
        $this->footers = new \ArrayObject();

        return new readonly class($this->footers) implements TranslatorInterface {
            /** @param \ArrayObject<int, string> $footers */
            public function __construct(private \ArrayObject $footers)
            {
            }

            /** @param array<array-key, mixed> $parameters */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                \assert(\is_int($parameters['page']) && \is_int($parameters['pages']));

                $footer = \sprintf('Page %d of %d', $parameters['page'], $parameters['pages']);
                $this->footers[] = $footer;

                return $footer;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }
}
