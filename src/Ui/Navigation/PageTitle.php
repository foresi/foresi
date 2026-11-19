<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Navigation;

use Foresi\Ui\Contract\Navigation\NavigationItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The document title of a page, from the specific to the general: the page, the navigation item it
 * belongs to, the product, as "Fix the login · Projects · Foresi". The front-loaded order follows
 * W3C Easy Checks (page title) and WebAIM, as a browser tab shows only the start of a title; the
 * section part follows the HMRC page title pattern; the spaced middle dot is GitHub's and GitLab's
 * separator. The section is left out where the page is the section itself, as on a list page, and
 * where the page belongs to no item, as on the login page.
 */
final readonly class PageTitle
{
    public const string SEPARATOR = ' · ';

    public function __construct(
        private NavigationRegistry $registry,
        private TranslatorInterface $translator,
        #[Autowire(param: 'app.product_name')]
        private string $productName,
    ) {
    }

    /**
     * @param string      $page         the page's own title as plain text, its heading
     * @param string|null $currentRoute route of the page, to find its section; null for a page outside the shell
     */
    #[AsTwigFunction('page_title')]
    public function compose(string $page, ?string $currentRoute = null): string
    {
        $parts = [trim($page)];
        $item = null === $currentRoute ? null : $this->registry->current($currentRoute);
        if ($item instanceof NavigationItem) {
            $parts[] = $this->translator->trans($item->labelKey);
        }
        $parts[] = $this->productName;

        $unique = [];
        foreach ($parts as $part) {
            if ('' !== $part && !\in_array(mb_strtolower($part), array_map(mb_strtolower(...), $unique), true)) {
                $unique[] = $part;
            }
        }

        return implode(self::SEPARATOR, $unique);
    }
}
