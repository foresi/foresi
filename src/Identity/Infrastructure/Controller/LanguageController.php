<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Controller;

use App\Identity\Application\ChangeLocale;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Shared\Infrastructure\Http\LocalPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Language switch of the user menu: stores the locale on the user and goes
 * back to the page the user came from (same-origin paths only).
 */
final class LanguageController extends AbstractController
{
    public const string ROUTE = 'app_account_language';
    public const string TOKEN_ID = 'switch_language';

    public function __construct(private readonly ChangeLocale $changeLocale)
    {
    }

    #[Route('/account/language', name: self::ROUTE, methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] SecurityUser $securityUser): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN_ID, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        $locale = $request->request->getString('locale');
        if (!\in_array($locale, User::LOCALES, true)) {
            throw new BadRequestHttpException('Unsupported locale.');
        }

        ($this->changeLocale)($securityUser->id(), $locale);

        return new RedirectResponse(LocalPath::resolve($request->request->getString('redirect'), $this->generateUrl('app_dashboard')));
    }
}
