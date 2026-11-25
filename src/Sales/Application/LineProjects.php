<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Application;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The projects an hourly line may link to, from the source installed
 * (LineProjectSource); with none, there are no projects, and a line given
 * one is refused as for an unknown project.
 */
final readonly class LineProjects
{
    private ?LineProjectSource $source;

    /**
     * @param iterable<LineProjectSource> $sources
     */
    public function __construct(#[AutowireIterator(LineProjectSource::TAG)] iterable $sources = [])
    {
        $first = null;
        foreach ($sources as $source) {
            $first = $source;
            break;
        }
        $this->source = $first;
    }

    /** Whether projects can be linked at all: Work is installed. */
    public function installed(): bool
    {
        return $this->source instanceof LineProjectSource;
    }

    public function find(string $projectId): ?LineProject
    {
        return $this->source?->find($projectId);
    }

    /**
     * @return list<LineProject>
     */
    public function ofClient(string $clientId): array
    {
        return $this->source?->ofClient($clientId) ?? [];
    }

    public function billableMinutes(string $projectId): int
    {
        return $this->source?->billableMinutes($projectId) ?? 0;
    }
}
