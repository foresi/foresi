<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Mail;

use App\Identity\Domain\Membership;
use App\Work\Infrastructure\Controller\NotificationController;
use App\Work\Infrastructure\Controller\NotificationSettingsController;
use App\Work\Infrastructure\Controller\TaskController;
use App\Work\Infrastructure\Controller\UnsubscribeController;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The absolute links of a notification e-mail. The link that stops a kind of
 * e-mail names the organisation, the person and the kind, and is signed with
 * the secret of the installation, so it works without signing in and cannot
 * be changed to stop someone else's. It does not expire, as an old e-mail
 * should still stop the next ones.
 */
final readonly class NotificationLinks
{
    public function __construct(private UrlGeneratorInterface $urls, private UriSigner $signer)
    {
    }

    public function task(string $taskId): string
    {
        return $this->urls->generate(TaskController::ROUTE, ['id' => $taskId], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function inbox(): string
    {
        return $this->urls->generate(NotificationController::ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public function settings(): string
    {
        return $this->urls->generate(NotificationSettingsController::ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * @param string $what the value of a kind, or the digest
     */
    public function unsubscribe(Membership $membership, string $what): string
    {
        return $this->signer->sign($this->urls->generate(UnsubscribeController::ROUTE, [
            'tenant' => $membership->tenantId()->toString(),
            'membership' => $membership->id(),
            'what' => $what,
            'lang' => $membership->user()->locale(),
        ], UrlGeneratorInterface::ABSOLUTE_URL));
    }
}
