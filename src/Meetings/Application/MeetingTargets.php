<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Application;

use Foresi\Meetings\Contract\MeetingTarget;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The record kinds a meeting can be about, from the MeetingTarget of each
 * installed module: a project and a contact always, more with other modules.
 */
final class MeetingTargets
{
    /** @var array<string, MeetingTarget>|null by kind */
    private ?array $byKind = null;

    /**
     * @param iterable<MeetingTarget> $targets
     */
    public function __construct(
        #[AutowireIterator(MeetingTarget::TAG)]
        private readonly iterable $targets,
    ) {
    }

    /**
     * @return list<string>
     */
    public function kinds(): array
    {
        return array_keys($this->all());
    }

    public function has(string $kind): bool
    {
        return isset($this->all()[$kind]);
    }

    public function canLink(string $kind, string $id): bool
    {
        return $this->target($kind)?->canLink($kind, $id) ?? false;
    }

    /**
     * What a person sees of a linked record: its name and screen, or null when it is gone or hidden.
     *
     * @return array{kind: string, id: string, label: string, url: ?string}|null
     */
    public function view(string $kind, string $id): ?array
    {
        $target = $this->target($kind);
        if (!$target instanceof MeetingTarget || !$target->canSee($kind, $id)) {
            return null;
        }
        $label = $target->label($kind, $id);

        return null === $label ? null : ['kind' => $kind, 'id' => $id, 'label' => $label, 'url' => $target->url($kind, $id)];
    }

    private function target(string $kind): ?MeetingTarget
    {
        return $this->all()[$kind] ?? null;
    }

    /**
     * @return array<string, MeetingTarget>
     */
    private function all(): array
    {
        if (null === $this->byKind) {
            $this->byKind = [];
            foreach ($this->targets as $target) {
                foreach ($target->kinds() as $kind) {
                    if (isset($this->byKind[$kind])) {
                        throw new \LogicException(\sprintf('Two meeting targets answer for the kind "%s".', $kind));
                    }
                    $this->byKind[$kind] = $target;
                }
            }
        }

        return $this->byKind;
    }
}
