<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Development component catalogue: every Twig component in every state.
 *
 * Registered in the dev and test environments only (see config/services.yaml),
 * so in production neither the service nor the route exists. It shows no data
 * and works without a tenant.
 */
final class CatalogueController extends AbstractController
{
    /**
     * Section templates in display order, base components first; the name is the
     * kebab-case component name and the anchor "component-<name>".
     */
    public const array SECTIONS = [
        'button', 'icon-button', 'badge', 'tag', 'field', 'text-input', 'select', 'checkbox', 'choice-chips', 'textarea',
        'search-field', 'radio-cards', 'card', 'description-list', 'tabs', 'stepper', 'alert', 'empty-state', 'kbd',
        'data-table', 'pagination', 'bulk-bar', 'action-bar', 'kpi-strip', 'facts-grid', 'timeline', 'progress-bar', 'mapping-row', 'page-header', 'command-palette',
    ];

    private const array LOCALES = ['cs', 'en'];

    public function __construct(private readonly LocaleSwitcher $locales)
    {
    }

    #[Route('/_catalogue', name: 'catalogue', methods: ['GET'])]
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
}
