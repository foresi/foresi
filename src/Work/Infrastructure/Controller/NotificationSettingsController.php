<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Controller;

use App\Identity\Application\OpenToGuest;
use App\Work\Application\ManageNotificationPreferences;
use App\Work\Application\NotificationMailer;
use App\Work\Application\WorkRefused;
use App\Work\Domain\EmailDelivery;
use App\Work\Domain\NotificationKind;
use App\Work\Domain\NotificationPreference;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The person's own choice of e-mails about their notifications, in their
 * account, a guest's too: per kind of event at once, in the daily digest or
 * not at all, the hour of the digest, or no e-mail at all (the matrix of
 * Linear, ClickUp and Asana, with the inbox always on). Only a mention and
 * becoming responsible can come at once. Saved from the docked action bar.
 */
#[OpenToGuest]
final class NotificationSettingsController extends AbstractController
{
    public const string ROUTE = 'app_notification_settings';
    public const string TOKEN = 'notification_settings';

    public function __construct(
        private readonly ManageNotificationPreferences $preferences,
        private readonly NotificationMailer $mailer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/account/notifications', name: self::ROUTE, methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::TOKEN, $request->request->getString('_token'))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }
            $this->refused(fn () => $this->preferences->save($this->deliveries($request), $this->hour($request), $request->request->getBoolean('noEmail')));
            $this->addFlash('success', $this->translator->trans('work.notification_settings.saved'));

            return $this->redirectToRoute(self::ROUTE);
        }
        $preference = $this->refused($this->preferences->mine(...));

        return $this->render('work/notifications/settings.html.twig', [
            'configured' => $this->mailer->isConfigured(),
            'events' => array_map(fn (NotificationKind $kind): array => [
                'kind' => $kind->value,
                'label' => $this->translator->trans('work.notification_settings.event.'.$kind->value),
                'value' => $preference->delivery($kind)->value,
                'options' => $this->options($kind),
            ], NotificationKind::cases()),
            'hours' => array_combine(range(0, 23), array_map(static fn (int $hour): string => sprintf('%d:00', $hour), range(0, 23))),
            'digestHour' => $preference->digestHour(),
            'noEmail' => $preference->wantsNoEmail(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function options(NotificationKind $kind): array
    {
        $options = [];
        foreach (EmailDelivery::cases() as $delivery) {
            if (NotificationPreference::allows($kind, $delivery)) {
                $options[$delivery->value] = $this->translator->trans('work.notification_settings.delivery.'.$delivery->value);
            }
        }

        return $options;
    }

    /**
     * @return array<string, EmailDelivery>
     */
    private function deliveries(Request $request): array
    {
        $deliveries = [];
        foreach (NotificationKind::cases() as $kind) {
            $value = $request->request->getString('email_'.$kind->value);
            $delivery = EmailDelivery::tryFrom($value);
            if (!$delivery instanceof EmailDelivery || !NotificationPreference::allows($kind, $delivery)) {
                throw new BadRequestHttpException(sprintf('No such choice for %s.', $kind->value));
            }
            $deliveries[$kind->value] = $delivery;
        }

        return $deliveries;
    }

    private function hour(Request $request): int
    {
        $hour = $request->request->getString('digestHour');
        if (1 !== preg_match('/^(?:[0-9]|1[0-9]|2[0-3])$/', $hour)) {
            throw new BadRequestHttpException('The digest comes at an hour from 0 to 23.');
        }

        return (int) $hour;
    }

    /**
     * @template T
     *
     * @param callable(): T $action
     *
     * @return T
     */
    private function refused(callable $action): mixed
    {
        try {
            return $action();
        } catch (WorkRefused $refused) {
            throw new AccessDeniedHttpException('Only a member of the organisation has notifications.', $refused);
        }
    }
}
