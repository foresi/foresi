<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Event;

use Foresi\Shared\Domain\Event\EventSubject;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Tells where the subject of an event can be read, for the module that owns
 * the subject types, so the feed links each event to its record without
 * knowing the routes of any module.
 */
#[AutoconfigureTag(self::SUBJECT_URL_TAG)]
interface EventSubjectUrl
{
    public const string SUBJECT_URL_TAG = 'foresi.shared.event_subject_url';

    /**
     * The id of the module whose subjects this links.
     */
    public function module(): string;

    /**
     * The path of the subject in the API, or null when it has none.
     */
    public function url(EventSubject $subject): ?string;
}
