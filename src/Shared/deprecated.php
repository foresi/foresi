<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

/*
 * The deprecated names of what moved from Foresi\Shared\Domain\Event into
 * Foresi\Shared\Contract\Event (ADR 0044), loaded on first use with a
 * deprecation. They cannot be files in Domain/Event, as Doctrine reads every
 * file of a mapped directory and would report them all. Composer includes this
 * file ("autoload.files" of foresi/shared); the names go in module API 3.0.
 */
spl_autoload_register(static function (string $class): void {
    $moved = [
        'Foresi\\Shared\\Domain\\Event\\DomainEvent' => [Foresi\Shared\Contract\Event\DomainEvent::class, 'class'],
        'Foresi\\Shared\\Domain\\Event\\DomainEventPayload' => [Foresi\Shared\Contract\Event\DomainEventPayload::class, 'interface'],
        'Foresi\\Shared\\Domain\\Event\\EventSubject' => [Foresi\Shared\Contract\Event\EventSubject::class, 'class'],
    ];
    if (!isset($moved[$class])) {
        return;
    }
    [$new, $kind] = $moved[$class];
    trigger_deprecation('foresi/shared', '0.11', 'The "%s" %s is deprecated, use "%s" instead.', $class, $kind, $new);
    class_alias($new, $class);
});
