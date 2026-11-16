<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Application\Event;

use App\Shared\Domain\Event\EventSubject;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Tells where the subject of an event can be read, for the module that owns
 * the subject types, so the feed links each event to its record without
 * knowing the routes of any module.
 */
#[AutoconfigureTag('app.event_subject_url')]
interface EventSubjectUrl
{
    /**
     * The id of the module whose subjects this links.
     */
    public function module(): string;

    /**
     * The path of the subject in the API, or null when it has none.
     */
    public function url(EventSubject $subject): ?string;
}
