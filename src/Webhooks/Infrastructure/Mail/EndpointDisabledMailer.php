<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Webhooks\Infrastructure\Mail;

use Foresi\Identity\Application\MembershipDirectory;
use Foresi\Identity\Application\UserRepository;
use Foresi\Identity\Domain\User;
use Foresi\Shared\Application\SystemMail;
use Foresi\Shared\Application\SystemMailer;
use Foresi\Shared\Application\SystemMailUnavailable;
use Foresi\Webhooks\Domain\DisabledReason;
use Foresi\Webhooks\Infrastructure\Controller\WebhookEndpointController;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Tells the owner of an endpoint that it was disabled, as system mail in their
 * language with their organisation in the footer: which host, why, and the link
 * to the endpoint, where it can be enabled again. It names the host only, not
 * the whole address, which may carry a token in its path or query, and never
 * the secret.
 *
 * The mail is queued, so it is sent with the transaction that disabled the
 * endpoint. A mail that cannot be queued, because the installation has no
 * system mailer or the owner is gone, is logged and the disabling stands. Any
 * other failure of the mail is logged too and never thrown: it would roll back
 * the recorded attempt or the whole relay batch.
 */
final readonly class EndpointDisabledMailer
{
    public function __construct(
        private SystemMailer $mailer,
        private UserRepository $users,
        private MembershipDirectory $directory,
        private TranslatorInterface $translator,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
    ) {
    }

    public function notify(string $ownerId, string $endpointId, string $host, DisabledReason $reason): void
    {
        try {
            $this->send($ownerId, $endpointId, $host, $reason);
        } catch (SystemMailUnavailable|\InvalidArgumentException $e) {
            $this->logger->warning('The owner of the disabled webhook endpoint {endpoint} was not told: {reason}', ['endpoint' => $endpointId, 'reason' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('The owner of the disabled webhook endpoint {endpoint} was not told: the mail failed with {class}.', ['endpoint' => $endpointId, 'class' => $e::class]);
        }
    }

    private function send(string $ownerId, string $endpointId, string $host, DisabledReason $reason): void
    {
        $owner = $this->users->findById($ownerId);
        if (!$owner instanceof User) {
            $this->logger->warning('The owner of the disabled webhook endpoint {endpoint} was not told: no such person.', ['endpoint' => $endpointId]);

            return;
        }

        $locale = $owner->locale();
        $context = [
            'locale' => $locale,
            'reason' => $this->translator->trans('webhooks.disabled_mail.reason.'.$reason->value, ['host' => $host], null, $locale),
            'host' => $host,
            'endpointUrl' => $this->urls->generate(WebhookEndpointController::SHOW_ROUTE, ['id' => $endpointId], UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        $this->mailer->send(new SystemMail(
            $owner->email(),
            $this->translator->trans('webhooks.disabled_mail.subject', ['host' => $host], null, $locale),
            trim($this->twig->render('@Webhooks/email/endpoint_disabled.txt.twig', $context)),
            $this->twig->render('@Webhooks/email/endpoint_disabled.html.twig', $context),
            $locale,
            $this->directory->organisationOf($ownerId)?->name,
        ));
    }
}
