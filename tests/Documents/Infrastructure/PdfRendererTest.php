<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Documents\Infrastructure;

use Foresi\Documents\Application\TemplateRenderer;
use Foresi\Documents\Domain\PageSettings;
use Foresi\Documents\Infrastructure\Pdf\TcpdfDocumentRenderer;
use Foresi\Shared\Infrastructure\Pdf\PdfEngine;
use Foresi\Tests\Support\VeraPdf;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The sample contract of the spike (ADR 0042) as a PDF/A-3a document.
 */
final class PdfRendererTest extends TestCase
{
    /** @var list<string> the footers drawn, in page order */
    private array $footers = [];

    private const array VALUES = [
        'firm' => ['name' => 'Příkladná firma s.r.o.', 'address_lines' => ['Žižkova 1234/5', '602 00 Brno'], 'registration_number' => '12345678', 'vat_id' => 'CZ12345678'],
        'client' => ['name' => 'Žluťoučký kůň a.s.', 'address_lines' => ['Náměstí Míru 7', '120 00 Praha 2'], 'registration_number' => '87654321', 'persons' => [['name' => 'Jana Nováková', 'email' => 'jana@example.com']]],
    ];

    #[Test]
    public function the_sample_contract_renders_two_pages_of_pdf_a_with_footers(): void
    {
        $html = new TemplateRenderer()->render((string) file_get_contents(__DIR__.'/../Fixtures/contract.html.twig'), self::VALUES);

        $pdf = $this->renderer()->render($html, new PageSettings(), 'Non-disclosure agreement', 'en');

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(2, preg_match_all('~/Type\s*/Page[^s]~', $pdf));
        foreach (['InterTabular-Regular', 'InterTabular-SemiBold', 'InterDisplay-SemiBold'] as $font) {
            self::assertMatchesRegularExpression('~/BaseFont /[A-Z]{6}\+'.$font.'~', $pdf);
        }
        self::assertStringContainsString('/FontFile2', $pdf);
        self::assertSame(['Page 1 of 2', 'Page 2 of 2'], $this->footers);
        VeraPdf::assertConforms($pdf, '3a');
    }

    #[Test]
    public function the_page_settings_set_the_paper(): void
    {
        $pdf = $this->renderer()->render('<p>x</p>', new PageSettings(landscape: true), 'T', 'en');

        self::assertSame(1, preg_match('~/MediaBox \[[\d.]+ [\d.]+ ([\d.]+) ([\d.]+)\]~', $pdf, $box));
        self::assertSame([841.9, 595.3], [round((float) $box[1], 1), round((float) $box[2], 1)]);
    }

    private function renderer(): TcpdfDocumentRenderer
    {
        $translator = new class($this->footers) implements TranslatorInterface {
            /** @param list<string> $footers */
            public function __construct(private array &$footers)
            {
            }

            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                \assert(\is_int($parameters['page']) && \is_int($parameters['pages']));

                return $this->footers[] = \sprintf('Page %d of %d', $parameters['page'], $parameters['pages']);
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        return new TcpdfDocumentRenderer(new PdfEngine(), $translator);
    }
}
