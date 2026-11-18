<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Development component catalogue: every Twig component in every state.
 *
 * Registered outside production only (see src/Ui/config/services.php), so in
 * production neither the service nor the route exists. It shows no data
 * and works without a signed-in person: the default _public opens it to anyone
 * (Foresi\Shared\Infrastructure\Security\PublicRoute).
 */
final class CatalogueController extends AbstractController
{
    /**
     * Section templates in display order: the design tokens, then base components;
     * the name is the kebab-case component name and the anchor "component-<name>".
     */
    public const array SECTIONS = [
        'tokens', 'button', 'icon-button', 'badge', 'tag', 'avatar', 'field', 'text-input', 'select', 'checkbox', 'choice-chips', 'textarea', 'markdown-editor',
        'search-field', 'combobox', 'person-picker', 'multi-select', 'inline-edit', 'menu', 'menu-item', 'menu-section', 'more-actions', 'radio-cards', 'field-group', 'form-band', 'disclosure', 'card', 'description-list', 'tabs', 'stepper', 'alert', 'empty-state', 'kbd',
        'filter-bar', 'data-table', 'pagination', 'bulk-bar', 'action-bar', 'dialog', 'sheet', 'kpi-strip', 'facts-grid', 'timeline', 'progress-bar', 'mapping-row', 'page-header', 'command-palette',
    ];

    private const array LOCALES = ['cs', 'en'];

    public function __construct(private readonly LocaleSwitcher $locales)
    {
    }

    #[Route('/_catalogue', name: 'catalogue', defaults: ['_public' => true], methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $locale = $request->query->getString('locale');
        $locale = in_array($locale, self::LOCALES, true) ? $locale : 'cs';
        $request->setLocale($locale);
        $this->locales->setLocale($locale);

        $sections = self::SECTIONS;
        $frame = 'mobile' === $request->query->getString('frame');
        if ($frame) {
            $section = $request->query->getString('section');
            if (!in_array($section, self::SECTIONS, true)) {
                throw $this->createNotFoundException();
            }
            $sections = [$section];
        }

        return $this->render('catalogue/index.html.twig', [
            'sections' => $sections,
            'frame' => $frame,
            'locale' => $locale,
            'locales' => self::LOCALES,
        ]);
    }

    /**
     * A page that opens as a sheet from the catalogue (a link with data-sheet), so the
     * real Ui:Sheet can be tried: motion, Escape, the scrim, the resize grip, a phone.
     */
    #[Route('/_catalogue/sheet', name: 'catalogue_sheet', defaults: ['_public' => true], methods: ['GET'])]
    public function sheet(Request $request): Response
    {
        $this->locales->setLocale('cs');
        $size = 'sm' === $request->query->getString('size') ? 'sm' : 'md';

        return $this->render('catalogue/sheet.html.twig', ['sheet_size' => $size]);
    }
}
