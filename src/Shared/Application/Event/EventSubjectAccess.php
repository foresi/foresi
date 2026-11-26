<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Event;

use Foresi\Shared\Contract\Event\EventSubject;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * For a module whose records are not all seen by everyone who reads its
 * events, such as a private task: whether the caller, or a given person, sees
 * the subject of one event. The feed leaves out an event whose subject is not
 * seen, and a webhook does not send it. A module without one shows every event
 * to whom EventReadAccess lets read them.
 */
#[AutoconfigureTag(self::SUBJECT_ACCESS_TAG)]
interface EventSubjectAccess
{
    public const string SUBJECT_ACCESS_TAG = 'foresi.shared.event_subject_access';

    /**
     * The id of the module whose events this decides on.
     */
    public function module(): string;

    public function sees(EventSubject $subject): bool;

    /**
     * Whether a given person, signed in or not, sees the subject in the active
     * organisation, for work done on their behalf, such as the webhooks they own.
     */
    public function userSees(string $userId, EventSubject $subject): bool;
}
