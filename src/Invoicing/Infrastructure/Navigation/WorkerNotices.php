<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Navigation;

use App\Identity\Application\OwnerCheck;
use App\Tenancy\Application\Schedule\Heartbeats;
use App\Ui\Notice\ShellNotice;
use App\Ui\Notice\ShellNoticeProvider;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells owners on every page that the background worker stopped: it beat
 * before, but not for Heartbeats::WORKER_STALE_AFTER. Mail, recurring invoices
 * and bank mail wait for it. An installation whose worker never ran says so on
 * /health only, as it has no beat to be late.
 */
final readonly class WorkerNotices implements ShellNoticeProvider
{
    public function __construct(
        private OwnerCheck $owners,
        private Heartbeats $heartbeats,
        private ClockInterface $clock,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function notices(): iterable
    {
        if (!$this->owners->isOwner()) {
            return;
        }
        $last = $this->heartbeats->last(Heartbeats::WORKER);
        if (!$last instanceof \DateTimeImmutable || $this->clock->now()->getTimestamp() - $last->getTimestamp() <= Heartbeats::WORKER_STALE_AFTER) {
            return;
        }
        yield new ShellNotice('error', $this->translator->trans('shell.worker.down'), $this->translator->trans('shell.worker.link'), $this->urls->generate('app_health'));
    }
}
