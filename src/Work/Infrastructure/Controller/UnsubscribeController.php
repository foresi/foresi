<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Controller;

use Foresi\Identity\Application\OpenToGuest;
use Foresi\Identity\Domain\User;
use Foresi\Work\Application\ManageNotificationPreferences;
use Foresi\Work\Domain\NotificationKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * The link at the foot of a notification e-mail that stops that kind of
 * e-mail, opened without signing in: its signature says whose it is. Opening
 * it asks first, as mail filters follow links; the button posts back to the
 * same link, and so does a mail client's one-click unsubscribe (RFC 8058),
 * with no session and no CSRF token, which the signature stands in for. A
 * link changed in any part is not found. Links sent before carry one more
 * path segment ahead of the person, which is signed with the rest and
 * otherwise ignored, so they still work.
 */
#[OpenToGuest]
final class UnsubscribeController extends AbstractController
{
    public const string ROUTE = 'app_notification_unsubscribe';

    public const string LEGACY_ROUTE = 'app_notification_unsubscribe_legacy';

    private const array HEADERS = [
        'X-Robots-Tag' => 'noindex, nofollow',
        'Referrer-Policy' => 'no-referrer',
        'Cache-Control' => 'private, no-store',
    ];

    public function __construct(private readonly UriSigner $signer, private readonly ManageNotificationPreferences $preferences)
    {
    }

    #[Route('/unsubscribe/{membership}/{what}', name: self::ROUTE, requirements: ['membership' => Requirement::UUID_V7, 'what' => '[a-z_]{1,32}'], methods: ['GET', 'POST'])]
    #[Route('/unsubscribe/{legacy}/{membership}/{what}', name: self::LEGACY_ROUTE, requirements: ['legacy' => Requirement::UUID_V7, 'membership' => Requirement::UUID_V7, 'what' => '[a-z_]{1,32}'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $membership, string $what): Response
    {
        if (!$this->signer->checkRequest($request)) {
            throw new NotFoundHttpException('The link is not valid.');
        }
        $done = false;
        if ($request->isMethod('POST')) {
            if (!$this->preferences->switchOff($membership, $what)) {
                throw new NotFoundHttpException('The link is not valid.');
            }
            $done = true;
        } elseif (!NotificationKind::tryFrom($what) instanceof NotificationKind && ManageNotificationPreferences::DIGEST !== $what) {
            throw new NotFoundHttpException('The link is not valid.');
        }

        // The language of the person, signed into the link with the rest.
        $locale = \in_array($request->query->getString('lang'), User::LOCALES, true) ? $request->query->getString('lang') : $request->getLocale();
        $request->setLocale($locale);
        $response = $this->render('work/notifications/unsubscribe.html.twig', [
            'locale' => $locale,
            'what' => $what,
            'done' => $done,
            'action' => $request->getUri(),
        ]);
        $response->headers->add(self::HEADERS);

        return $response;
    }
}
