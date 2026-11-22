<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Documents\Infrastructure\Screen;

use Foresi\Documents\Application\DocumentsRefused;
use Foresi\Documents\Application\TemplateRenderer;
use Foresi\Documents\Domain\PageSettings;

/**
 * A template filled with values as an HTML page of its own, for the preview
 * frame of the template editor and of a draft. The frame is sandboxed and the
 * page runs nothing: the sandboxed renderer escapes every value, and the
 * margins of the paper show as the page's padding. A refused template gives
 * the refusal instead, as a sentence of the person's language.
 */
final readonly class TemplatePreview
{
    public function __construct(private TemplateRenderer $renderer, private DocumentScreen $screen)
    {
    }

    /**
     * @param array<string, mixed> $values by prefix
     *
     * @return array{html: ?string, error: ?string}
     */
    public function preview(string $body, array $values, PageSettings $page): array
    {
        try {
            $html = $this->renderer->render($body, $values);
        } catch (DocumentsRefused $refused) {
            return ['html' => null, 'error' => $this->screen->refusal($refused)];
        }

        return ['html' => self::page($html, $page), 'error' => null];
    }

    /**
     * The rendered body inside a page whose padding is the paper's margins.
     */
    public static function page(string $html, PageSettings $page): string
    {
        $padding = \sprintf('%smm %smm %smm %smm', self::mm($page->marginTopMm), self::mm($page->marginRightMm), self::mm($page->marginBottomMm), self::mm($page->marginLeftMm));

        return '<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;padding:'.$padding.';font-family:Inter,system-ui,sans-serif;font-size:11pt;line-height:1.4}</style></head><body>'.$html.'</body></html>';
    }

    private static function mm(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
