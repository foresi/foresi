<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Notice;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Twig\Attribute\AsTwigFunction;

/** Collects the notices of all providers for the app shell. */
final readonly class ShellNotices
{
    public const string TAG = 'app.shell_notice';

    /** More than this would push the page itself out of view. */
    private const int MAX = 3;

    /**
     * @param iterable<ShellNoticeProvider> $providers
     */
    public function __construct(
        #[AutowireIterator(self::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * @param bool $tenantActive providers read tenant-owned data, so they are skipped without a tenant
     *
     * @return list<ShellNotice>
     */
    #[AsTwigFunction('shell_notices')]
    public function all(bool $tenantActive): array
    {
        if (!$tenantActive) {
            return [];
        }
        $notices = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->notices() as $notice) {
                $notices[] = $notice;
                if (self::MAX === \count($notices)) {
                    return $notices;
                }
            }
        }

        return $notices;
    }
}
