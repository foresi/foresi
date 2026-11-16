<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Api;

use App\Shared\Application\Event\EventSubjectUrl;
use App\Shared\Domain\Event\EventSubject;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Where the subject of an event can be read, asked of the module that owns the
 * event. A module that does not say gives its events no link.
 */
final readonly class SubjectUrls
{
    /**
     * @param iterable<EventSubjectUrl> $providers
     */
    public function __construct(
        #[AutowireIterator('app.event_subject_url')]
        private iterable $providers,
    ) {
    }

    public function url(string $module, EventSubject $subject): ?string
    {
        foreach ($this->providers as $provider) {
            if ($provider->module() === $module) {
                return $provider->url($subject);
            }
        }

        return null;
    }
}
