<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Twig;

use App\Ui\Dialog\DialogRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * Lets a page template that can also be a dialog pick its layout:
 * {% extends in_dialog() ? 'layout/dialog.html.twig' : 'layout/app.html.twig' %}.
 * A record detail extends layout/record.html.twig instead, which asks overlay()
 * whether a sheet, a dialog or nothing (the page) loads it.
 */
final readonly class DialogFunctions
{
    public function __construct(private RequestStack $requests)
    {
    }

    /** True for both layers, the sheet and the modal dialog. */
    #[AsTwigFunction('in_dialog')]
    public function inDialog(): bool
    {
        return null !== $this->overlay();
    }

    /** "sheet", "dialog", or null when the page itself is asked for. */
    #[AsTwigFunction('overlay')]
    public function overlay(): ?string
    {
        $request = $this->requests->getMainRequest();

        return $request instanceof Request ? DialogRequest::layer($request) : null;
    }
}
