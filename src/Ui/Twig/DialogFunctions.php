<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Twig;

use App\Ui\Dialog\DialogRequest;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * Lets a page template that can also be a dialog pick its layout:
 * {% extends in_dialog() ? 'layout/dialog.html.twig' : 'layout/app.html.twig' %}.
 */
final readonly class DialogFunctions
{
    public function __construct(private RequestStack $requests)
    {
    }

    #[AsTwigFunction('in_dialog')]
    public function inDialog(): bool
    {
        $request = $this->requests->getMainRequest();

        return $request instanceof \Symfony\Component\HttpFoundation\Request && DialogRequest::is($request);
    }
}
